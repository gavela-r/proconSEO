<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Run\Interruptor;
use ProconSEO\Run\LanzadorAnalisis;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Run\RepositorioAnalisis;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Tests\Fetch\DnsFalso;

/**
 * SEG-11 (límites e interruptor general) y SEG-15 (auditoría), contra la base de datos local.
 * Se salta si no hay base de datos.
 */
final class LimitesAuditoriaTest extends TestCase
{
    private PDO $bd;
    private int $proyectoId;
    private Interruptor $interruptor;
    private LanzadorAnalisis $lanzador;
    private int $maxPorIp;
    private int $maxPorDominio;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM registro_auditoria LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Límites', 'https://ejemplo.es/', 'web_kit_digital', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();

        $repo = new RepositorioAnalisis($this->bd);
        $abuso = $repo->perfilVigente()['parametros']['abuso'];
        $this->maxPorIp = $abuso['analisis_por_ip_hora']['valor'];
        $this->maxPorDominio = $abuso['analisis_por_dominio_hora']['valor'];

        $auditoria = new RegistroAuditoria($this->bd);
        $this->interruptor = new Interruptor($this->bd, $auditoria);
        $dns = new DnsFalso(['ejemplo.es' => ['93.184.216.34'], 'www.ejemplo.es' => ['93.184.216.34'], 'otra.es' => ['93.184.216.35']]);
        $this->lanzador = new LanzadorAnalisis($repo, new ValidadorDestino(new ValidadorUrl(), $dns), CatalogoJson::v1(), $this->interruptor, $auditoria);
    }

    protected function tearDown(): void
    {
        if (!isset($this->proyectoId)) {
            return;
        }
        if ($this->interruptor->activo()) {
            $this->interruptor->desactivar('test');
        }
        $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        // Solo los tests limpian la auditoría; la aplicación no puede.
        $this->bd->prepare('DELETE FROM registro_auditoria WHERE proyecto_id = ? OR ip_origen LIKE ?')->execute([$this->proyectoId, '192.0.2.%']);
        $this->bd->exec("DELETE FROM registro_auditoria WHERE accion LIKE 'interruptor_%' AND detalle LIKE '%test%'");
    }

    private function lanzar(string $url, string $ip): int
    {
        return $this->lanzador->lanzar($this->proyectoId, $url, 'url_individual', null, '', $ip);
    }

    private function rechazo(callable $accion): ErrorDominio
    {
        try {
            $accion();
        } catch (ErrorDominio $e) {
            return $e;
        }
        self::fail('Se esperaba un rechazo');
    }

    private function auditoria(string $accion): array
    {
        $consulta = $this->bd->prepare('SELECT * FROM registro_auditoria WHERE proyecto_id = ? AND accion = ? ORDER BY id');
        $consulta->execute([$this->proyectoId, $accion]);

        return $consulta->fetchAll();
    }

    public function testLanzarGuardaIpYDominioYQuedaEnLaAuditoria(): void
    {
        $id = $this->lanzar('https://www.ejemplo.es/', '192.0.2.10');

        $fila = (new RepositorioAnalisis($this->bd))->obtener($id);
        self::assertSame('192.0.2.10', $fila['ip_origen']);
        self::assertSame('ejemplo.es', $fila['dominio'], 'www. y sin www. cuentan como el mismo dominio');

        $entradas = $this->auditoria(RegistroAuditoria::ANALISIS_LANZADO);
        self::assertCount(1, $entradas);
        self::assertSame($id, (int) $entradas[0]['analisis_id']);
        self::assertSame('192.0.2.10', $entradas[0]['ip_origen']);
    }

    public function testLimitePorDominioYHora(): void
    {
        for ($i = 0; $i < $this->maxPorDominio; $i++) {
            $this->lanzar($i % 2 === 0 ? 'https://ejemplo.es/' : 'https://www.ejemplo.es/', '192.0.2.' . (20 + $i));
        }

        $error = $this->rechazo(fn () => $this->lanzar('https://ejemplo.es/otra', '192.0.2.99'));
        self::assertSame('limite_por_dominio', $error->codigo);
        self::assertSame(429, $error->estadoHttp);

        // Otro dominio sigue funcionando.
        self::assertGreaterThan(0, $this->lanzar('https://otra.es/', '192.0.2.99'));

        $rechazos = $this->auditoria(RegistroAuditoria::LANZAMIENTO_RECHAZADO);
        self::assertSame('SEG-11', $rechazos[0]['regla']);
        self::assertSame('192.0.2.99', $rechazos[0]['ip_origen']);
    }

    public function testLimitePorIpYHora(): void
    {
        // Se insertan directamente para no chocar con el límite por dominio.
        $repo = new RepositorioAnalisis($this->bd);
        $perfil = $repo->perfilVigente();
        for ($i = 0; $i < $this->maxPorIp; $i++) {
            $repo->crear($this->proyectoId, 'https://x.es/', 'https://x.es/', \ProconSEO\Shared\Enum\Alcance::UrlIndividual, null, ['x.es'], $perfil['id'], 'v1', '192.0.2.50', "d{$i}.es");
        }

        $error = $this->rechazo(fn () => $this->lanzar('https://otra.es/', '192.0.2.50'));
        self::assertSame('limite_por_ip', $error->codigo);

        self::assertGreaterThan(0, $this->lanzar('https://otra.es/', '192.0.2.51'), 'Otra IP sí puede');
    }

    public function testUrlPeligrosaRechazadaQuedaEnLaAuditoria(): void
    {
        $this->rechazo(fn () => $this->lanzar('http://192.168.1.1/', '192.0.2.60'));

        $rechazos = $this->auditoria(RegistroAuditoria::LANZAMIENTO_RECHAZADO);
        self::assertCount(1, $rechazos);
        self::assertSame('SEG-02', $rechazos[0]['regla']);
        self::assertSame('http://192.168.1.1/', $rechazos[0]['url']);
    }

    public function testInterruptorDetieneLoQueHayYNoDejaLanzarMas(): void
    {
        $enCola = $this->lanzar('https://ejemplo.es/', '192.0.2.70');
        $enCurso = $this->lanzar('https://www.ejemplo.es/', '192.0.2.70');
        $this->bd->prepare("UPDATE analisis SET estado = 'en_curso' WHERE id = ?")->execute([$enCurso]);

        $this->interruptor->activar('Prueba de test', 'test');

        $repo = new RepositorioAnalisis($this->bd);
        self::assertSame('cancelado', $repo->obtener($enCola)['estado']);
        self::assertSame('cancelando', $repo->obtener($enCurso)['estado'], 'El worker lo cierra conservando lo obtenido');

        $error = $this->rechazo(fn () => $this->lanzar('https://otra.es/', '192.0.2.70'));
        self::assertSame('herramienta_detenida', $error->codigo);
        self::assertSame(503, $error->estadoHttp);

        $this->interruptor->desactivar('test');
        self::assertGreaterThan(0, $this->lanzar('https://otra.es/', '192.0.2.70'));
    }

    public function testLaAuditoriaSobreviveAlBorradoDelProyecto(): void
    {
        $id = $this->lanzar('https://ejemplo.es/', '192.0.2.80');
        $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);

        $consulta = $this->bd->prepare('SELECT COUNT(*) FROM registro_auditoria WHERE analisis_id = ?');
        $consulta->execute([$id]);
        self::assertSame(1, (int) $consulta->fetchColumn());
    }

    public function testLaAuditoriaNoTieneFormaDeModificarseNiBorrarse(): void
    {
        $metodos = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(RegistroAuditoria::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        self::assertSame([], array_values(array_filter($metodos, static fn (string $m): bool => (bool) preg_match('/borrar|eliminar|actualizar|modificar|purgar/i', $m))));
    }
}
