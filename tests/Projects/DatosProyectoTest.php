<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Projects\DatosProyecto;
use ProconSEO\Projects\ErroresFormulario;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Validación del formulario de proyectos (RF-01, RF-02, RF-03, RF-06).
 */
final class DatosProyectoTest extends TestCase
{
    public function testProyectoCompletoValido(): void
    {
        $datos = DatosProyecto::desdeFormulario([
            'nombre' => '  Web de Gasolineras Pérez ',
            'cliente' => 'Gasolineras Pérez S.L.',
            'url_principal' => 'https://www.gasolineras-perez.es/',
            'descripcion' => "Kit Digital.\nAvisar a Marta.",
            'estado' => 'en_pausa',
            'tipo' => 'web_cliente',
            'sector' => 'hidrocarburos',
        ]);

        self::assertSame('Web de Gasolineras Pérez', $datos->nombre);
        self::assertSame('Gasolineras Pérez S.L.', $datos->cliente);
        self::assertSame('https://www.gasolineras-perez.es/', $datos->urlPrincipal);
        self::assertSame("Kit Digital.\nAvisar a Marta.", $datos->descripcion, 'Las notas conservan los saltos de línea');
        self::assertSame(EstadoProyecto::EnPausa, $datos->estado);
        self::assertSame(TipoProyecto::WebCliente, $datos->tipo);
        self::assertSame(Sector::Hidrocarburos, $datos->sector);
    }

    public function testSoloElNombreEsObligatorio(): void
    {
        // RF-03: puede haber proyectos internos o de prueba sin cliente.
        $datos = DatosProyecto::desdeFormulario(['nombre' => 'Interno', 'cliente' => '', 'url_principal' => '   ', 'tipo' => '']);

        self::assertNull($datos->cliente);
        self::assertNull($datos->urlPrincipal);
        self::assertNull($datos->descripcion);
        self::assertNull($datos->tipo);
        self::assertSame(EstadoProyecto::Activo, $datos->estado, 'Sin estado, el proyecto nace activo');
    }

    public function testSinNombreNoSeGuarda(): void
    {
        $errores = $this->errores(['nombre' => "   \n "]);

        self::assertArrayHasKey('nombre', $errores);
    }

    public function testNombreDemasiadoLargo(): void
    {
        self::assertArrayHasKey('nombre', $this->errores(['nombre' => str_repeat('ñ', DatosProyecto::MAX_NOMBRE + 1)]));
        self::assertSame(DatosProyecto::MAX_NOMBRE, mb_strlen(DatosProyecto::desdeFormulario(['nombre' => str_repeat('ñ', DatosProyecto::MAX_NOMBRE)])->nombre));
    }

    /** @return iterable<string, array{string}> */
    public static function urlsNoValidas(): iterable
    {
        yield 'sin esquema' => ['www.ejemplo.es'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'ftp' => ['ftp://ejemplo.es/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'sin host' => ['https://'];
        yield 'con usuario y contraseña' => ['https://usuario:clave@ejemplo.es/'];
        yield 'demasiado larga' => ['https://ejemplo.es/' . str_repeat('a', DatosProyecto::MAX_URL)];
    }

    #[DataProvider('urlsNoValidas')]
    public function testDireccionNoValida(string $url): void
    {
        self::assertArrayHasKey('url_principal', $this->errores(['nombre' => 'Prueba', 'url_principal' => $url]));
    }

    public function testValoresFueraDeLaListaSeRechazan(): void
    {
        $errores = $this->errores(['nombre' => 'Prueba', 'tipo' => 'web_pirata', 'estado' => 'borrado']);

        self::assertArrayHasKey('tipo', $errores);
        self::assertArrayHasKey('estado', $errores);
    }

    public function testSectorNoValidoEnWebDeCliente(): void
    {
        self::assertArrayHasKey('sector', $this->errores(['nombre' => 'Prueba', 'tipo' => 'web_cliente', 'sector' => 'mineria']));
    }

    public function testElSectorSoloSeGuardaEnWebDeCliente(): void
    {
        $datos = DatosProyecto::desdeFormulario(['nombre' => 'Prueba', 'tipo' => 'web_kit_digital', 'sector' => 'salud']);

        self::assertSame(TipoProyecto::WebKitDigital, $datos->tipo);
        self::assertNull($datos->sector);
    }

    public function testCamposQueNoSonTextoSeIgnoran(): void
    {
        // Un formulario manipulado puede mandar arrays (nombre[]=x): no deben colarse ni romper nada.
        self::assertArrayHasKey('nombre', $this->errores(['nombre' => ['x'], 'cliente' => ['y']]));
    }

    public function testElNombreEsUnaSolaLinea(): void
    {
        self::assertSame('Uno dos', DatosProyecto::desdeFormulario(['nombre' => "Uno\r\n dos"])->nombre);
    }

    public function testElErrorLlevaLosCamposParaElJson(): void
    {
        try {
            DatosProyecto::desdeFormulario([]);
            self::fail('Debía lanzar ErroresFormulario');
        } catch (ErroresFormulario $e) {
            self::assertSame(422, $e->estadoHttp);
            self::assertSame('datos_no_validos', $e->comoArray()['error']['codigo']);
            self::assertArrayHasKey('nombre', $e->comoArray()['error']['detalles']['campos']);
        }
    }

    /** @return array<string, string> */
    private function errores(array $entrada): array
    {
        try {
            DatosProyecto::desdeFormulario($entrada);
        } catch (ErroresFormulario $e) {
            return $e->errores;
        }
        self::fail('Debía rechazar los datos');
    }
}
