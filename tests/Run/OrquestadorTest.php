<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Engine\Motor;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\PageSpeed\ClientePageSpeed;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Run\GuardadoResultados;
use ProconSEO\Run\Interruptor;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Run\LanzadorAnalisis;
use ProconSEO\Run\MotorProvisional;
use ProconSEO\Run\Orquestador;
use ProconSEO\Run\RepositorioAnalisis;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Contract\InformePageSpeed;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\TokenCancelacion;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Tests\Fetch\DnsFalso;
use ProconSEO\Tests\Fetch\RelojFalso;
use ProconSEO\Tests\Fetch\TransporteFalso;

/**
 * Flujo completo de un análisis contra la base de datos local, con la red simulada.
 * Crea un proyecto de prueba y lo borra al terminar. Se salta si no hay base de datos.
 */
final class OrquestadorTest extends TestCase
{
    private PDO $bd;
    private int $proyectoId;
    private TransporteFalso $transporte;
    private DnsFalso $dns;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM perfiles_ejecucion LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Orquestador', 'https://ejemplo.es/', 'web_kit_digital', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();

        $this->transporte = new TransporteFalso();
        $this->dns = new DnsFalso(['ejemplo.es' => ['93.184.216.34'], 'trampa.es' => ['93.184.216.40']]);
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
            // Solo los tests limpian la auditoría; la aplicación no puede.
            $this->bd->prepare('DELETE FROM registro_auditoria WHERE proyecto_id = ?')->execute([$this->proyectoId]);
        }
    }

    private function validador(): ValidadorDestino
    {
        return new ValidadorDestino(new ValidadorUrl(), $this->dns);
    }

    private function lanzador(): LanzadorAnalisis
    {
        $auditoria = new RegistroAuditoria($this->bd);

        return new LanzadorAnalisis(new RepositorioAnalisis($this->bd), $this->validador(), CatalogoJson::v1(), new Interruptor($this->bd, $auditoria), $auditoria);
    }

    private function orquestador(?MotorAnalisis $motor = null, ?ClientePageSpeed $pageSpeed = null): Orquestador
    {
        $reloj = new RelojFalso();

        return new Orquestador(
            new RepositorioAnalisis($this->bd),
            new GuardadoResultados($this->bd),
            $motor ?? new MotorProvisional(CatalogoJson::v1()),
            $this->validador(),
            fn (Ambito $a, LimitesDescarga $l): ClienteHttpSeguro => new ClienteHttpSeguro($this->transporte, $this->validador(), $a, $l, $reloj),
            $pageSpeed,
        );
    }

    private function lanzar(string $url = 'https://ejemplo.es/', string $alcance = 'url_individual'): int
    {
        $id = $this->lanzador()
            ->lanzar($this->proyectoId, $url, $alcance, 'Prueba');
        $this->bd->prepare("UPDATE analisis SET estado = 'en_curso' WHERE id = ?")->execute([$id]);

        return $id;
    }

    private function valor(string $sql, array $parametros): mixed
    {
        $consulta = $this->bd->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchColumn();
    }

    public function testAnalisisCompletoGuardaUnResultadoPorSubcomprobacion(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], "User-agent: *\nAllow: /"));
        $this->transporte->responder(new RespuestaHttp(200, ['content-type' => 'text/html', 'set-cookie' => 'x=secreto'], '<html><title>Hola</title></html>'));
        $id = $this->lanzar();

        $estado = $this->orquestador()->ejecutar($id);

        self::assertSame(EstadoAnalisis::Completado, $estado);
        self::assertSame(count(CatalogoJson::v1()->todas()), (int) $this->valor('SELECT COUNT(*) FROM resultados WHERE analisis_id = ?', [$id]));
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND estado <> 'no_verificable'", [$id]), 'Lo no implementado solo puede ser no_verificable');
        self::assertSame(2, (int) $this->valor('SELECT COUNT(*) FROM recursos WHERE analisis_id = ?', [$id]));
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM fases_analisis WHERE analisis_id = ? AND estado IN ('pendiente', 'en_curso')", [$id]));
        self::assertSame('omitida', $this->valor("SELECT estado FROM fases_analisis WHERE analisis_id = ? AND fase = 'renderizado'", [$id]));
    }

    public function testSitioCompletoAnalizaCadaPaginaConElMotorReal(): void
    {
        $html = static fn (string ...$hrefs): RespuestaHttp => new RespuestaHttp(200, ['content-type' => 'text/html'],
            '<html lang="es"><head><title>T</title></head><body>' . implode('', array_map(static fn (string $h): string => "<a href=\"{$h}\">x</a>", $hrefs)) . '</body></html>');
        $this->transporte->porUrl = [
            'https://ejemplo.es/robots.txt' => new RespuestaHttp(200, ['content-type' => 'text/plain'], "User-agent: *\nDisallow: /privado"),
            'https://ejemplo.es/' => $html('/servicios', '/contacto', '/privado/x', 'https://otra.es/'),
            'https://ejemplo.es/servicios' => $html('/', '/contacto'),
            'https://ejemplo.es/contacto' => $html('/'),
            'https://ejemplo.es/sitemap.xml' => new RespuestaHttp(404),
        ];
        $id = $this->lanzar('https://ejemplo.es/', 'sitio_completo');

        $estado = $this->orquestador(Motor::v1())->ejecutar($id);

        self::assertSame(EstadoAnalisis::Completado, $estado);
        self::assertStringContainsString('3 páginas analizadas, 1 no permitidas por robots.txt', (string) $this->valor("SELECT mensaje FROM fases_analisis WHERE analisis_id = ? AND fase = 'descubrimiento'", [$id]));
        foreach (['https://ejemplo.es/servicios', 'https://ejemplo.es/contacto'] as $pagina) {
            self::assertGreaterThan(0, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND url_recurso = ? AND cobertura <> 'no_implementada'", [$id, $pagina]), "Resultados de {$pagina}");
        }
        $noImplementadas = count(array_filter(Motor::v1()->catalogo()->todas(), static fn ($f): bool => $f->cobertura === \ProconSEO\Shared\Enum\Cobertura::NoImplementada));
        self::assertSame($noImplementadas, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND cobertura = 'no_implementada'", [$id]), 'Lo no implementado aparece una sola vez, no por página');
        // Ni el rastreo ni las comprobaciones (p. ej., la de enlaces rotos) piden lo que robots.txt prohíbe.
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND url_recurso LIKE '%privado%'", [$id]), 'No se analiza lo que robots.txt prohíbe');
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM recursos WHERE analisis_id = ? AND url LIKE '%privado%' AND resultado <> 'fuera_de_ambito'", [$id]), 'Nadie lo descarga');
        self::assertStringContainsString('robots.txt', (string) $this->valor("SELECT motivo FROM recursos WHERE analisis_id = ? AND url LIKE '%privado%' AND resultado = 'fuera_de_ambito'", [$id]),
            'Pero queda apuntado en la lista de recursos con el motivo, si alguna comprobación lo pidió');
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM recursos WHERE analisis_id = ? AND url LIKE '%otra.es%'", [$id]));

        // Las tres páginas se llaman «T»: al terminar el recorrido se comparan entre sí, una sola vez.
        self::assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND id_comprobacion = 'SEO-03.title-duplicado'", [$id]), 'Una sola fila para todo el sitio');
        self::assertSame('mejorable', $this->valor("SELECT estado FROM resultados WHERE analisis_id = ? AND id_comprobacion = 'SEO-03.title-duplicado'", [$id]));
        self::assertStringContainsString('https://ejemplo.es/servicios', (string) $this->valor("SELECT contenido FROM evidencias WHERE analisis_id = ? AND codigo LIKE 'S-%'", [$id]));
        // La misma causa en varias páginas es un solo grupo.
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM grupos_causa WHERE analisis_id = ? AND REGEXP_LIKE(codigo, '^P[0-9]+-', 'c')", [$id]));
    }

    public function testUrlConContextoNoRecorreElSitio(): void
    {
        $this->transporte->porUrl = [
            'https://ejemplo.es/robots.txt' => new RespuestaHttp(200, [], "User-agent: *\nAllow: /"),
            'https://ejemplo.es/' => new RespuestaHttp(200, ['content-type' => 'text/html'], '<html><body><a href="/otra">x</a></body></html>'),
        ];
        $id = $this->lanzar('https://ejemplo.es/', 'url_contexto');

        $this->orquestador(Motor::v1())->ejecutar($id);

        self::assertStringContainsString('URLs relacionadas que pedían las comprobaciones (máximo 20)', (string) $this->valor("SELECT mensaje FROM fases_analisis WHERE analisis_id = ? AND fase = 'descubrimiento'", [$id]));
        // Una comprobación puede consultar el enlace (p. ej., para ver si está roto), pero no se analiza como página.
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM resultados WHERE analisis_id = ? AND url_recurso = 'https://ejemplo.es/otra'", [$id]), 'Los enlaces no se analizan como páginas');
        self::assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM recursos WHERE analisis_id = ? AND url = 'https://ejemplo.es/otra' AND justificado_por IS NULL", [$id]), 'Solo lo pide una comprobación, nunca el rastreo');
    }

    public function testLaPaginaAnalizadaLlevaSusInformesDePageSpeed(): void
    {
        $this->transporte->porUrl = [
            'https://ejemplo.es/robots.txt' => new RespuestaHttp(200, [], ''),
            'https://ejemplo.es/' => new RespuestaHttp(200, ['content-type' => 'text/html'], '<html></html>'),
        ];
        $google = new TransporteFalso();
        $google->responder(new RespuestaHttp(200, [], (string) json_encode(['lighthouseResult' => ['audits' => ['largest-contentful-paint' => ['numericValue' => 2000]]]])));
        $google->responder(new RespuestaHttp(429, [], '{}'));
        $this->dns->tabla['www.googleapis.com'] = ['142.250.184.10'];
        $pageSpeed = new ClientePageSpeed($google, $this->validador(), 'clave-de-prueba', 'proconSEO/1.0');

        // Motor que solo guarda el contexto que recibe.
        $motor = new class () implements MotorAnalisis {
            public ?ContextoAnalisis $contexto = null;

            public function ejecutar(ContextoAnalisis $c, ProveedorRecursos $p, InformadorProgreso $i, TokenCancelacion $t): SalidaMotor
            {
                $this->contexto = $c;

                return new SalidaMotor();
            }
        };

        $this->orquestador($motor, $pageSpeed)->ejecutar($this->lanzar());

        self::assertCount(2, $google->peticiones, 'Móvil y ordenador');
        self::assertSame(1, $google->tandas, 'Pedidos a la vez, no uno detrás de otro');
        self::assertSame(2000.0, $motor->contexto?->pageSpeed(InformePageSpeed::MOVIL)?->laboratorio['lcp_ms']);
        self::assertStringContainsString('cuota', (string) $motor->contexto?->pageSpeed(InformePageSpeed::ORDENADOR)?->error);
    }

    public function testSiLaPaginaNoSeDescargaNoSeLlamaAPageSpeed(): void
    {
        $this->transporte->porUrl = [
            'https://ejemplo.es/robots.txt' => new RespuestaHttp(200, [], ''),
            'https://ejemplo.es/' => new RespuestaHttp(404),
        ];
        $google = new TransporteFalso();
        $this->dns->tabla['www.googleapis.com'] = ['142.250.184.10'];

        $this->orquestador(null, new ClientePageSpeed($google, $this->validador(), 'clave', 'proconSEO/1.0'))->ejecutar($this->lanzar());

        self::assertCount(0, $google->peticiones);
    }

    public function testPaginaQueNoRespondeDaAnalisisParcial(): void
    {
        $this->transporte->responder(new RespuestaHttp(404));
        $this->transporte->responder(new RespuestaHttp(500));
        $this->transporte->responder(new RespuestaHttp(500));
        $this->transporte->responder(new RespuestaHttp(500));
        $id = $this->lanzar();

        $estado = $this->orquestador()->ejecutar($id);

        self::assertSame(EstadoAnalisis::Parcial, $estado);
        self::assertStringContainsString('HTTP 500', (string) $this->valor('SELECT motivo_cierre FROM analisis WHERE id = ?', [$id]));
    }

    public function testDominioQueCambiaAIpInternaSeDetienePorSeguridad(): void
    {
        $id = $this->lanzar('https://trampa.es/');
        $this->dns->tabla['trampa.es'] = ['127.0.0.1']; // cambia entre encolar y ejecutar

        $estado = $this->orquestador()->ejecutar($id);

        self::assertSame(EstadoAnalisis::DetenidoSeguridad, $estado);
        self::assertCount(0, $this->transporte->peticiones, 'No se conecta a nada');
        self::assertSame('SEG-02', $this->valor('SELECT regla FROM eventos_seguridad WHERE analisis_id = ?', [$id]));
        self::assertSame('SEG-02', $this->valor("SELECT regla FROM registro_auditoria WHERE analisis_id = ? AND accion = 'bloqueo_seguridad'", [$id]), 'El bloqueo queda también en la auditoría');
    }

    public function testCancelacionDuranteLasComprobacionesConservaLoObtenido(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], ''));
        $this->transporte->responder(new RespuestaHttp(200, [], '<html></html>'));
        $id = $this->lanzar();
        $repo = new RepositorioAnalisis($this->bd);

        // Motor que pide la cancelación a mitad de trabajo, como haría el usuario con el botón.
        $motor = new class ($repo, $id) implements MotorAnalisis {
            public function __construct(private RepositorioAnalisis $repo, private int $id)
            {
            }

            public function ejecutar(ContextoAnalisis $c, ProveedorRecursos $p, InformadorProgreso $i, TokenCancelacion $t): SalidaMotor
            {
                $this->repo->pedirCancelacion($this->id);
                usleep(1_100_000); // el token consulta la base de datos como mucho una vez por segundo

                return (new MotorProvisional(CatalogoJson::v1()))->ejecutar($c, $p, $i, $t);
            }
        };

        $estado = $this->orquestador($motor)->ejecutar($id);

        self::assertSame(EstadoAnalisis::Cancelado, $estado);
        self::assertSame(2, (int) $this->valor('SELECT COUNT(*) FROM recursos WHERE analisis_id = ?', [$id]), 'Se conservan los recursos descargados');
        self::assertLessThan(count(CatalogoJson::v1()->todas()), (int) $this->valor('SELECT COUNT(*) FROM resultados WHERE analisis_id = ?', [$id]));
    }

    public function testLanzadorRechazaUrlInsegura(): void
    {
        $this->expectException(\ProconSEO\Fetch\DestinoRechazado::class);
        $this->lanzador()
            ->lanzar($this->proyectoId, 'http://192.168.1.1/', 'url_individual', null);
    }

    public function testCancelarEnColaLoCierraDirectamente(): void
    {
        $id = $this->lanzador()
            ->lanzar($this->proyectoId, 'https://ejemplo.es/', 'url_individual', null);

        (new RepositorioAnalisis($this->bd))->pedirCancelacion($id);

        self::assertSame('cancelado', $this->valor('SELECT estado FROM analisis WHERE id = ?', [$id]));
    }
}
