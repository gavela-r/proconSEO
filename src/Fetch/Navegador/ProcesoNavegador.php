<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use RuntimeException;

/**
 * Arranca Chrome o Edge sin ventana y sin red propia (SEG-04, SEG-08).
 *
 * - Perfil nuevo en una carpeta temporal para cada página, que se borra al terminar: no se
 *   comparten cookies, almacenamiento local ni service workers entre análisis.
 * - El navegador no puede resolver nombres (todo resuelve a «no encontrado») y su proxy es un
 *   puerto cerrado: si una petición se escapara de la interceptación, falla en vez de salir.
 *   Las peticiones las hace ClienteHttpSeguro, con sus validaciones (ver NavegadorAislado).
 * - La sandbox del navegador sigue activa: nunca se usa --no-sandbox.
 * - El canal de control solo escucha en 127.0.0.1, en un puerto elegido por el sistema, y
 *   dura lo que dura la página.
 */
final class ProcesoNavegador
{
    /** Rutas habituales en Windows; NAVEGADOR_RUTA en .env tiene prioridad. */
    private const RUTAS = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    /** @var resource|null */
    private $proceso = null;

    private ?ConexionCdp $conexion = null;

    private function __construct(private readonly string $perfil)
    {
    }

    /** El ejecutable configurado o el primero que exista; null si no hay ninguno. */
    public static function buscarEjecutable(?string $configurado = null): ?string
    {
        if ($configurado !== null && $configurado !== '') {
            return is_file($configurado) ? $configurado : null;
        }
        foreach (self::RUTAS as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    /**
     * Opciones de arranque. Públicas para poder comprobarlas en los tests.
     *
     * @return list<string>
     */
    public static function argumentos(string $perfil): array
    {
        return [
            '--headless=new',
            '--user-data-dir=' . $perfil,
            '--remote-debugging-address=127.0.0.1',
            '--remote-debugging-port=0',
            // Sin red propia: nada se resuelve y el proxy es un puerto cerrado (127.0.0.1:9, discard).
            '--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE 127.0.0.1',
            '--proxy-server=http://127.0.0.1:9',
            '--proxy-bypass-list=<-loopback>',
            '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
            '--webrtc-ip-handling-policy=disable_non_proxied_udp',
            // Sin tráfico propio del navegador ni de Google/Microsoft.
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-default-apps',
            '--disable-extensions',
            '--disable-sync',
            '--disable-breakpad',
            '--disable-crash-reporter',
            '--disable-domain-reliability',
            '--disable-client-side-phishing-detection',
            '--no-pings',
            '--dns-prefetch-disable',
            '--disable-features=Translate,OptimizationHints,MediaRouter,AutofillServerCommunication,NetworkPrediction,InterestFeedContentSuggestions,CertificateTransparencyComponentUpdater,EdgeCollections,msEdgeShopping',
            // Sin ventanas nuevas, sonido, avisos ni permisos.
            '--block-new-web-contents',
            '--deny-permission-prompts',
            '--mute-audio',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-gpu',
            '--hide-scrollbars',
            '--window-size=1366,768',
            'about:blank',
        ];
    }

    /** Arranca el navegador y abre el canal de control. */
    public static function arrancar(string $ejecutable, float $segundos = 15.0): self
    {
        $perfil = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'proconseo-nav-' . bin2hex(random_bytes(8));
        if (!@mkdir($perfil, 0700)) {
            throw new RuntimeException('No se pudo crear el perfil temporal del navegador.');
        }
        $yo = new self($perfil);
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proceso = @proc_open(
            [$ejecutable, ...self::argumentos($perfil)],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias,
            null,
            self::entornoLimpio(),
            ['bypass_shell' => true],
        );
        if ($proceso === false) {
            $yo->borrarPerfil();
            throw new RuntimeException('No se pudo arrancar el navegador.');
        }
        $yo->proceso = $proceso;

        // El navegador escribe aquí el puerto y la ruta del canal de control.
        $fichero = $perfil . DIRECTORY_SEPARATOR . 'DevToolsActivePort';
        $limite = microtime(true) + $segundos;
        while (true) {
            $lineas = is_file($fichero) ? @file($fichero, FILE_IGNORE_NEW_LINES) : false;
            if (is_array($lineas) && count($lineas) >= 2 && ctype_digit($lineas[0]) && str_starts_with($lineas[1], '/devtools/browser/')) {
                break;
            }
            if (!proc_get_status($proceso)['running'] || microtime(true) > $limite) {
                $yo->cerrar();
                throw new RuntimeException('El navegador no arrancó a tiempo.');
            }
            usleep(50_000);
        }
        try {
            $yo->conexion = ConexionCdp::abrir((int) $lineas[0], $lineas[1]);
        } catch (RuntimeException $e) {
            $yo->cerrar();
            throw $e;
        }

        return $yo;
    }

    public function conexion(): ConexionCdp
    {
        return $this->conexion ?? throw new RuntimeException('El navegador no está arrancado.');
    }

    /** Cierra el navegador (pidiéndolo y, si no, a la fuerza) y borra su perfil. */
    public function cerrar(): void
    {
        if ($this->conexion !== null) {
            try {
                $this->conexion->comando('Browser.close', [], null, 3.0);
            } catch (RuntimeException) {
                // Se cierra a la fuerza más abajo.
            }
            $this->conexion->cerrar();
            $this->conexion = null;
        }
        if ($this->proceso !== null) {
            $limite = microtime(true) + 5.0;
            while (proc_get_status($this->proceso)['running'] && microtime(true) < $limite) {
                usleep(50_000);
            }
            $estado = proc_get_status($this->proceso);
            if ($estado['running']) {
                if (PHP_OS_FAMILY === 'Windows') {
                    // proc_terminate solo cierra el proceso principal; /T cierra también sus hijos.
                    @exec('taskkill /F /T /PID ' . $estado['pid'] . ' 2>NUL');
                } else {
                    proc_terminate($this->proceso, 9);
                }
            }
            proc_close($this->proceso);
            $this->proceso = null;
        }
        $this->borrarPerfil();
    }

    public function __destruct()
    {
        $this->cerrar();
    }

    /**
     * Entorno mínimo para el navegador: sin las variables de la aplicación (.env), para que
     * ninguna clave llegue al proceso que ejecuta el JavaScript de la web (SEG-08, SEG-17).
     *
     * @return array<string, string>
     */
    private static function entornoLimpio(): array
    {
        $entorno = [];
        foreach (['SystemRoot', 'SYSTEMROOT', 'windir', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'PATH', 'HOME', 'TMPDIR', 'LANG'] as $clave) {
            $valor = getenv($clave);
            if (is_string($valor) && $valor !== '') {
                $entorno[$clave] = $valor;
            }
        }

        return $entorno;
    }

    private function borrarPerfil(): void
    {
        if (!is_dir($this->perfil)) {
            return;
        }
        // Windows tarda un momento en soltar los ficheros del perfil.
        for ($intento = 0; $intento < 10 && is_dir($this->perfil); $intento++) {
            self::borrarCarpeta($this->perfil);
            if (is_dir($this->perfil)) {
                usleep(200_000);
            }
        }
    }

    private static function borrarCarpeta(string $carpeta): void
    {
        $elementos = @scandir($carpeta);
        foreach ($elementos === false ? [] : $elementos as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $ruta = $carpeta . DIRECTORY_SEPARATOR . $e;
            if (is_dir($ruta) && !is_link($ruta)) {
                self::borrarCarpeta($ruta);
            } else {
                @unlink($ruta);
            }
        }
        @rmdir($carpeta);
    }
}
