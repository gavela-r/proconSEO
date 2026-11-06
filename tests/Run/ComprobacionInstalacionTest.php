<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PHPUnit\Framework\TestCase;
use ProconSEO\Run\ComprobacionInstalacion as C;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * bin/comprobar.php: en una instalación correcta (la local o la de la revisión automática,
 * que se monta desde cero) no hay errores, y nunca se enseñan contraseñas ni claves.
 */
final class ComprobacionInstalacionTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = dirname(__DIR__, 2);
        Config::cargar($this->raiz);
        try {
            BaseDatos::conexion()->query('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
    }

    /** @param list<array{nivel: string, texto: string}> $lineas */
    private static function textos(array $lineas, string $nivel): array
    {
        return array_column(array_filter($lineas, static fn (array $l): bool => $l['nivel'] === $nivel), 'texto');
    }

    public function testUnaInstalacionCorrectaNoTieneErrores(): void
    {
        $c = new C($this->raiz);
        $lineas = $c->ejecutar();

        self::assertSame([], self::textos($lineas, C::ERROR));
        self::assertFalse($c->hayErrores());
        self::assertNotEmpty(array_filter(self::textos($lineas, C::OK), static fn (string $t): bool => str_starts_with($t, 'Base de datos al día')));
    }

    public function testNuncaEnsenaContrasenasNiClaves(): void
    {
        $texto = implode("\n", array_column((new C($this->raiz, true))->ejecutar(), 'texto'));

        foreach (['DB_PASS', 'PAGESPEED_API_KEY'] as $clave) {
            $valor = (string) Config::obtener($clave, '');
            if (strlen($valor) >= 4) {
                self::assertStringNotContainsString($valor, $texto, "No se enseña {$clave}");
            }
        }
    }

    public function testEnProduccionExigeAppEnvProduccion(): void
    {
        if (Config::obtener('APP_ENV') === 'produccion') {
            self::markTestSkipped('Este entorno ya es de producción.');
        }

        $c = new C($this->raiz, true);
        $errores = self::textos($c->ejecutar(), C::ERROR);

        self::assertTrue($c->hayErrores());
        self::assertNotEmpty(array_filter($errores, static fn (string $t): bool => str_contains($t, 'APP_ENV')));
    }

    public function testAvisaDeMigracionesSinAplicar(): void
    {
        $falsa = sys_get_temp_dir() . '/proconseo-comprobar-' . bin2hex(random_bytes(4));
        mkdir($falsa . '/db/migrations', 0777, true);
        mkdir($falsa . '/storage');
        touch($falsa . '/db/migrations/2099_01_01_0001_nadie_pendiente.sql');
        file_put_contents($falsa . '/.env', '# vacío: la conexión sale de la configuración ya cargada');
        try {
            $errores = self::textos((new C($falsa))->ejecutar(), C::ERROR);
        } finally {
            unlink($falsa . '/db/migrations/2099_01_01_0001_nadie_pendiente.sql');
            unlink($falsa . '/.env');
            rmdir($falsa . '/db/migrations');
            rmdir($falsa . '/db');
            rmdir($falsa . '/storage');
            rmdir($falsa);
        }

        self::assertNotEmpty(array_filter($errores, static fn (string $t): bool => str_contains($t, '2099_01_01_0001_nadie_pendiente.sql') && str_contains($t, 'migrar')));
    }
}
