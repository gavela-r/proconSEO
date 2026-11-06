<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use Throwable;

/**
 * Comprueba que una instalación tiene todo lo necesario (bin/comprobar.php). Pensada para
 * sistemas: cada línea dice qué falta y cómo arreglarlo, sin enseñar nunca contraseñas ni claves.
 *
 * Niveles: «ok», «aviso» (funciona, pero algo no irá del todo) y «error» (no funcionará).
 */
final class ComprobacionInstalacion
{
    public const OK = 'ok';
    public const AVISO = 'aviso';
    public const ERROR = 'error';

    /** Extensiones sin las que no funciona, con lo que fallaría. */
    private const EXTENSIONES = [
        'curl' => 'descargar las webs',
        'dom' => 'leer el HTML',
        'intl' => 'dominios con tildes o eñes',
        'json' => 'guardar la configuración',
        'mbstring' => 'textos con tildes',
        'pdo_mysql' => 'conectar con MySQL',
        'openssl' => 'webs con https',
        'zlib' => 'sitemaps comprimidos',
    ];

    /** @var list<array{nivel: string, texto: string}> */
    private array $lineas = [];

    /**
     * @param bool $produccion Comprobar también los ajustes de producción (APP_ENV, php.ini)
     * @param bool $red        Hacer una petición https real a example.com
     */
    public function __construct(private readonly string $raiz, private readonly bool $produccion = false, private readonly bool $red = false)
    {
    }

    /** @return list<array{nivel: string, texto: string}> */
    public function ejecutar(): array
    {
        $this->lineas = [];
        $this->php();
        $bd = $this->baseDatos();
        if ($bd !== null) {
            $this->migraciones($bd);
            $this->estado($bd);
        }
        $this->carpetas();
        $this->certificados();
        $this->claves();
        if ($this->red) {
            $this->probarRed();
        }

        return $this->lineas;
    }

    public function hayErrores(): bool
    {
        foreach ($this->lineas as $l) {
            if ($l['nivel'] === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    /** Una petición https real a example.com, para ver que cURL verifica bien los certificados. */
    private function probarRed(): void
    {
        if (!function_exists('curl_init')) {
            return;
        }
        $c = curl_init('https://example.com/');
        curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'proconSEO/comprobacion']);
        $ok = curl_exec($c) !== false;
        $error = curl_error($c);
        curl_close($c);
        $ok
            ? $this->ok('Conexión https a internet con el certificado verificado.')
            : $this->error("No se pudo abrir https://example.com/ ({$error}). Revisa el cortafuegos de salida o los certificados de cURL.");
    }

    private function php(): void
    {
        PHP_VERSION_ID >= 80200
            ? $this->ok('PHP ' . PHP_VERSION . '.')
            : $this->error('PHP ' . PHP_VERSION . ': hace falta la 8.2 o superior.');

        $faltan = array_filter(array_keys(self::EXTENSIONES), static fn (string $e): bool => !extension_loaded($e));
        foreach ($faltan as $e) {
            $this->error("Falta la extensión de PHP «{$e}» (sin ella no se puede " . self::EXTENSIONES[$e] . ').');
        }
        if ($faltan === []) {
            $this->ok('Extensiones de PHP: ' . implode(', ', array_keys(self::EXTENSIONES)) . '.');
        }
        if (!extension_loaded('gd')) {
            $this->aviso('Falta la extensión «gd»: no se podrán adjuntar capturas en las revisiones humanas.');
        }

        if ($this->produccion) {
            if (Config::obtener('APP_ENV') !== 'produccion') {
                $this->error('APP_ENV no es «produccion» en el .env: la cookie de sesión no irá marcada como segura.');
            }
            if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL)) {
                $this->error('display_errors está activado en php.ini: los errores se verían en pantalla.');
            }
            if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
                $this->aviso('allow_url_fopen está activado en php.ini: conviene desactivarlo (la red solo se usa con cURL).');
            }
        }
    }

    private function baseDatos(): ?PDO
    {
        if (!is_file($this->raiz . '/.env') && getenv('DB_NAME') === false) {
            $this->error('No hay fichero .env: copia .env.example como .env y rellena los datos de conexión.');

            return null;
        }
        try {
            $bd = BaseDatos::conexion();
            $version = (string) $bd->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            $this->error('No se puede conectar con MySQL. Revisa DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASS en el .env (' . self::sinSecretos($e->getMessage()) . ').');

            return null;
        }
        version_compare($version, '8.0', '>=')
            ? $this->ok("MySQL {$version}.")
            : $this->error("MySQL {$version}: hace falta la 8.0 o superior.");

        $juego = (string) $bd->query('SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn();
        if ($juego !== 'utf8mb4') {
            $this->error("La base de datos usa «{$juego}»: créala con CHARACTER SET utf8mb4.");
        }

        return $bd;
    }

    private function migraciones(PDO $bd): void
    {
        $ficheros = array_map('basename', glob($this->raiz . '/db/migrations/*.sql') ?: []);
        try {
            $aplicadas = $bd->query('SELECT fichero FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            $aplicadas = [];
        }
        $pendientes = array_values(array_diff($ficheros, $aplicadas));
        if ($pendientes !== []) {
            $this->error(count($pendientes) . ' migraciones sin aplicar (' . implode(', ', $pendientes) . '): ejecuta «php bin/migrar.php».');

            return;
        }
        $this->ok('Base de datos al día (' . count($ficheros) . ' migraciones).');

        $perfil = $bd->query('SELECT version FROM perfiles_ejecucion ORDER BY id DESC LIMIT 1')->fetchColumn();
        $perfil === false
            ? $this->error('No hay perfil de ejecución: ejecuta «php bin/migrar.php».')
            : $this->ok("Perfil de ejecución {$perfil}.");
    }

    private function estado(PDO $bd): void
    {
        try {
            $motivo = (new Interruptor($bd, new RegistroAuditoria($bd)))->motivo();
            if ($motivo !== null) {
                $this->aviso("El interruptor general está ACTIVADO ({$motivo}): no se pueden lanzar análisis. Para quitarlo: php bin/interruptor.php desactivar");
            }

            $enCola = (int) $bd->query("SELECT COUNT(*) FROM analisis WHERE estado = 'en_cola' AND creado_en < NOW(3) - INTERVAL 5 MINUTE")->fetchColumn();
            try {
                $programados = (int) $bd->query('SELECT COUNT(*) FROM programaciones WHERE activa = 1 AND siguiente_en < NOW(3) - INTERVAL 10 MINUTE')->fetchColumn();
            } catch (Throwable) {
                $programados = 0; // instalación sin análisis programados todavía
            }
            if ($enCola > 0 || $programados > 0) {
                $this->aviso(($enCola > 0 ? "{$enCola} análisis llevan más de 5 minutos en cola" : "{$programados} análisis programados van con retraso")
                    . ': parece que el worker no está en marcha (php bin/worker.php, o el servicio proconseo-worker).');
            } else {
                $this->ok('No hay análisis esperando al worker.');
            }
        } catch (Throwable $e) {
            $this->aviso('No se pudo comprobar el estado de los análisis (' . self::sinSecretos($e->getMessage()) . ').');
        }
    }

    private function carpetas(): void
    {
        $storage = $this->raiz . '/storage';
        is_dir($storage) && is_writable($storage)
            ? $this->ok('Se puede escribir en storage/ (capturas de las revisiones).')
            : $this->error('No se puede escribir en storage/: dale permiso de escritura a los usuarios de la web y del worker.');

        if (is_dir($this->raiz . '/public') && is_file($this->raiz . '/public/.env')) {
            $this->error('Hay un .env dentro de public/: se podría descargar. Muévelo a la raíz del proyecto.');
        }
    }

    private function certificados(): void
    {
        $fichero = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: (openssl_get_cert_locations()['default_cert_file'] ?? '');
        $carpeta = openssl_get_cert_locations()['default_cert_dir'] ?? '';
        if (($fichero !== '' && is_file($fichero)) || ($carpeta !== '' && is_dir($carpeta) && (glob($carpeta . '/*') ?: []) !== [])) {
            $this->ok('Hay certificados raíz para verificar las webs https.');
        } else {
            $this->error('cURL no tiene certificados raíz: ninguna web https se podrá analizar. Configura curl.cainfo en php.ini (ver README).');
        }
    }

    private function claves(): void
    {
        (string) Config::obtener('PAGESPEED_API_KEY', '') !== ''
            ? $this->ok('Clave de Google PageSpeed configurada.')
            : $this->aviso('Sin PAGESPEED_API_KEY: la velocidad de carga saldrá como «no verificable».');
    }

    /** Los mensajes de PDO pueden incluir el usuario: se quita todo lo que vaya entre comillas. */
    private static function sinSecretos(string $mensaje): string
    {
        return mb_substr((string) preg_replace("/'[^']*'/", "'…'", $mensaje), 0, 200);
    }

    private function ok(string $texto): void
    {
        $this->lineas[] = ['nivel' => self::OK, 'texto' => $texto];
    }

    private function aviso(string $texto): void
    {
        $this->lineas[] = ['nivel' => self::AVISO, 'texto' => $texto];
    }

    private function error(string $texto): void
    {
        $this->lineas[] = ['nivel' => self::ERROR, 'texto' => $texto];
    }
}
