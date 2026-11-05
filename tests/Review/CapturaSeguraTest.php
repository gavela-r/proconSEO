<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Review;

use PHPUnit\Framework\TestCase;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Subida segura de capturas (SEG-14): solo PNG o JPG por su contenido real, con límites,
 * recodificadas y guardadas fuera de public/.
 */
final class CapturaSeguraTest extends TestCase
{
    private const CARGA = '<?php system($_GET["c"]); ?>';

    private string $temporal;
    private string $destino;
    private CapturaSegura $capturas;

    protected function setUp(): void
    {
        $this->temporal = sys_get_temp_dir() . '/proconseo-test-' . bin2hex(random_bytes(4));
        $this->destino = $this->temporal . '/capturas';
        mkdir($this->temporal . '/subidas', 0700, true);
        // En la aplicación se exige is_uploaded_file(); aquí se usan ficheros de prueba.
        $this->capturas = new CapturaSegura($this->destino, soloSubidas: false);
    }

    protected function tearDown(): void
    {
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporal, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterador as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->temporal);
    }

    public function testPngValidoSeRecodificaYSeGuardaFueraDePublic(): void
    {
        // PNG real con texto escondido detrás del final de la imagen.
        $png = $this->imagen('png') . self::CARGA;

        $guardada = $this->capturas->guardar($this->subida($png, 'captura.png'), 'analisis-7');

        self::assertNotNull($guardada);
        self::assertSame('image/png', $guardada['mime']);
        self::assertMatchesRegularExpression('#^analisis-7/[a-f0-9]{32}\.png$#', $guardada['ruta'], 'Nombre aleatorio, no el del usuario');
        $contenido = (string) file_get_contents($this->destino . '/' . $guardada['ruta']);
        self::assertStringNotContainsString('system(', $contenido, 'La recodificación elimina lo que iba escondido');
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($contenido)[2]);
        self::assertStringNotContainsString('public', $this->capturas->rutaAbsoluta($guardada['ruta']) ?? 'public');
    }

    public function testJpgValido(): void
    {
        $guardada = $this->capturas->guardar($this->subida($this->imagen('jpg'), 'foto.jpeg'), 'analisis-7');

        self::assertSame('image/jpeg', $guardada['mime']);
        self::assertStringEndsWith('.jpg', $guardada['ruta']);
    }

    public function testElTipoSeComprueboPorElContenidoNoPorLaExtension(): void
    {
        $this->rechaza($this->subida(self::CARGA, 'captura.png', 'image/png'), 'PNG o JPG');
    }

    public function testOtrosFormatosDeImagenNoSeAdmiten(): void
    {
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $this->rechaza($this->subida((string) ob_get_clean(), 'animacion.png'), 'PNG o JPG');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->rechaza($this->subida($svg, 'logo.png', 'image/svg+xml'), 'PNG o JPG');
    }

    public function testDemasiadoGrandeEnBytes(): void
    {
        $this->rechaza($this->subida(str_repeat('a', CapturaSegura::MAX_BYTES + 1), 'grande.png'), 'demasiado grande');
        $this->rechaza(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'x.png'], 'demasiado grande');
    }

    public function testDemasiadoGrandeEnPixeles(): void
    {
        // PNG pequeño en bytes que dice medir 9000 × 9000 (bomba de descompresión).
        $png = $this->imagen('png');
        $png = substr_replace($png, pack('N', 9000) . pack('N', 9000), 16, 8);

        $this->rechaza($this->subida($png, 'bomba.png'), 'píxeles');
    }

    public function testImagenDanada(): void
    {
        $png = substr($this->imagen('png'), 0, 40);

        $this->rechaza($this->subida($png, 'rota.png'), 'dañada');
    }

    public function testSinFicheroNoEsUnError(): void
    {
        self::assertNull($this->capturas->guardar(null, 'x'));
        self::assertNull($this->capturas->guardar(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => ''], 'x'));
    }

    public function testSinSubidaHttpSeRechaza(): void
    {
        // En la aplicación, un fichero que no llega por una subida (p. ej. /etc/passwd) se rechaza.
        $estricta = new CapturaSegura($this->destino);

        $this->expectException(ErrorDominio::class);
        $estricta->guardar($this->subida($this->imagen('png'), 'captura.png'), 'x');
    }

    public function testRutaAbsolutaNoSaleDeLaCarpeta(): void
    {
        $guardada = $this->capturas->guardar($this->subida($this->imagen('png'), 'a.png'), 'analisis-1');

        self::assertNotNull($this->capturas->rutaAbsoluta($guardada['ruta']));
        self::assertNull($this->capturas->rutaAbsoluta('../../etc/passwd'));
        self::assertNull($this->capturas->rutaAbsoluta('analisis-1/../../' . basename($this->temporal) . '/x.png'));
        self::assertNull($this->capturas->rutaAbsoluta('analisis-1/' . str_repeat('0', 32) . '.png'), 'No existe');
        self::assertNull($this->capturas->rutaAbsoluta('analisis-1/' . str_repeat('a', 32) . '.php'));
    }

    private function imagen(string $tipo): string
    {
        $imagen = imagecreatetruecolor(40, 20);
        imagefill($imagen, 0, 0, (int) imagecolorallocate($imagen, 11, 92, 173));
        ob_start();
        $tipo === 'png' ? imagepng($imagen) : imagejpeg($imagen);

        return (string) ob_get_clean();
    }

    /** Simula una entrada de $_FILES. */
    private function subida(string $contenido, string $nombre, string $tipoDeclarado = 'image/png'): array
    {
        $ruta = $this->temporal . '/subidas/' . bin2hex(random_bytes(4));
        file_put_contents($ruta, $contenido);

        return ['name' => $nombre, 'type' => $tipoDeclarado, 'tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)];
    }

    private function rechaza(array $fichero, string $mensaje): void
    {
        try {
            $this->capturas->guardar($fichero, 'analisis-1');
            self::fail('Debía rechazar el fichero');
        } catch (ErrorDominio $e) {
            self::assertSame('captura_no_valida', $e->codigo);
            self::assertStringContainsString($mensaje, $e->getMessage());
            self::assertDirectoryDoesNotExist($this->destino . '/analisis-1', 'No se guarda nada');
        }
    }
}
