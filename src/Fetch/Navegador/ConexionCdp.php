<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use Closure;
use RuntimeException;

/**
 * Conexión con el navegador por el protocolo DevTools (CDP) sobre un WebSocket local.
 *
 * Solo se conecta a 127.0.0.1: es el canal de control del navegador que hemos arrancado
 * nosotros, no una petición a la web analizada (esas pasan por ClienteHttpSeguro).
 *
 * Los eventos con manejador (por ejemplo Fetch.requestPaused) se atienden en cuanto llegan,
 * también mientras se espera la respuesta de otro comando: si no, la navegación se quedaría
 * esperando a una petición que nadie contesta.
 */
final class ConexionCdp
{
    /** Tamaño máximo de un mensaje del navegador (el DOM entero cabe de sobra). */
    private const MAX_MENSAJE = 64 * 1024 * 1024;

    /** @var resource|closed-resource */
    private $socket;

    private int $siguienteId = 1;

    /** @var array<int, array{result?: array<string, mixed>, error?: array<string, mixed>}> */
    private array $respuestas = [];

    /** @var list<array{method: string, params: array<string, mixed>, sessionId: ?string}> */
    private array $eventos = [];

    /** @var array<string, Closure(array<string, mixed>, ?string): void> */
    private array $manejadores = [];

    private bool $cerrada = false;

    /** @param resource $socket */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /** Abre el WebSocket de ws://127.0.0.1:PUERTO/RUTA. */
    public static function abrir(int $puerto, string $ruta, float $segundos = 5.0): self
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $puerto, $errno, $error, $segundos);
        if ($socket === false) {
            throw new RuntimeException('No se pudo conectar con el navegador: ' . $error);
        }
        stream_set_timeout($socket, (int) ceil($segundos));
        $clave = base64_encode(random_bytes(16));
        fwrite($socket, "GET {$ruta} HTTP/1.1\r\nHost: 127.0.0.1:{$puerto}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$clave}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $cabecera = '';
        while (!str_contains($cabecera, "\r\n\r\n")) {
            $linea = fgets($socket);
            if ($linea === false) {
                throw new RuntimeException('El navegador no aceptó la conexión de control.');
            }
            $cabecera .= $linea;
            if (strlen($cabecera) > 8192) {
                throw new RuntimeException('Respuesta de conexión demasiado larga.');
            }
        }
        if (!preg_match('#^HTTP/1\.1 101#', $cabecera)) {
            throw new RuntimeException('El navegador rechazó la conexión de control.');
        }
        $esperado = base64_encode(sha1($clave . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^sec-websocket-accept:\s*(\S+)/mi', $cabecera, $m) || $m[1] !== $esperado) {
            throw new RuntimeException('La conexión de control no es la esperada.');
        }

        return new self($socket);
    }

    /** @param Closure(array<string, mixed>, ?string): void $manejador params y sessionId del evento */
    public function alRecibir(string $evento, Closure $manejador): void
    {
        $this->manejadores[$evento] = $manejador;
    }

    /**
     * Envía un comando y espera su respuesta.
     *
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>
     */
    public function comando(string $metodo, array $parametros = [], ?string $sesion = null, float $segundos = 30.0): array
    {
        $id = $this->siguienteId++;
        $mensaje = ['id' => $id, 'method' => $metodo];
        if ($parametros !== []) {
            $mensaje['params'] = $parametros;
        }
        if ($sesion !== null) {
            $mensaje['sessionId'] = $sesion;
        }
        $this->escribir((string) json_encode($mensaje, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $limite = microtime(true) + $segundos;
        while (!isset($this->respuestas[$id])) {
            $quedan = $limite - microtime(true);
            if ($quedan <= 0 || !$this->leerMensaje($quedan)) {
                throw new RuntimeException("El navegador no respondió a {$metodo} a tiempo.");
            }
        }
        $respuesta = $this->respuestas[$id];
        unset($this->respuestas[$id]);
        if (isset($respuesta['error'])) {
            $texto = is_string($respuesta['error']['message'] ?? null) ? $respuesta['error']['message'] : 'error desconocido';
            throw new RuntimeException("{$metodo}: {$texto}");
        }

        return $respuesta['result'] ?? [];
    }

    /**
     * Espera hasta que llegue un evento sin manejador (o se acabe el tiempo).
     *
     * @return array{method: string, params: array<string, mixed>, sessionId: ?string}|null
     */
    public function siguienteEvento(float $segundos): ?array
    {
        $limite = microtime(true) + $segundos;
        while (true) {
            if ($this->eventos !== []) {
                return array_shift($this->eventos);
            }
            $quedan = $limite - microtime(true);
            if ($quedan <= 0 || !$this->leerMensaje($quedan)) {
                return null;
            }
        }
    }

    /** Descarta los eventos pendientes de un tipo (por ejemplo, tras una espera ya resuelta). */
    public function olvidarEventos(): void
    {
        $this->eventos = [];
    }

    public function cerrar(): void
    {
        if ($this->cerrada) {
            return;
        }
        $this->cerrada = true;
        @fwrite($this->socket, self::trama(8, ''));
        @fclose($this->socket);
    }

    /** Lee y reparte un mensaje. Devuelve false si no llegó nada a tiempo. */
    private function leerMensaje(float $segundos): bool
    {
        $texto = $this->leerTexto($segundos);
        if ($texto === null) {
            return false;
        }
        $datos = json_decode($texto, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($datos)) {
            return true;
        }
        if (isset($datos['id']) && is_int($datos['id'])) {
            /** @var array{result?: array<string, mixed>, error?: array<string, mixed>} $datos */
            $this->respuestas[$datos['id']] = $datos;

            return true;
        }
        if (!isset($datos['method']) || !is_string($datos['method'])) {
            return true;
        }
        $params = is_array($datos['params'] ?? null) ? $datos['params'] : [];
        $sesion = is_string($datos['sessionId'] ?? null) ? $datos['sessionId'] : null;
        if (isset($this->manejadores[$datos['method']])) {
            ($this->manejadores[$datos['method']])($params, $sesion);
        } else {
            $this->eventos[] = ['method' => $datos['method'], 'params' => $params, 'sessionId' => $sesion];
        }

        return true;
    }

    /** Un mensaje de texto completo (une fragmentos y contesta a los ping). */
    private function leerTexto(float $segundos): ?string
    {
        $mensaje = '';
        while (true) {
            if (!is_resource($this->socket)) {
                throw new RuntimeException('La conexión con el navegador ya está cerrada.');
            }
            $leidos = [$this->socket];
            $nada = null;
            $seg = (int) floor($segundos);
            $useg = (int) (($segundos - (float) $seg) * 1_000_000.0);
            if (@stream_select($leidos, $nada, $nada, $seg, $useg) < 1) {
                return null;
            }
            [$fin, $codigo, $carga] = self::leerTrama(fn (int $n): string => $this->leerExacto($n));
            if ($codigo === 9) {
                $this->escribirTrama(10, $carga);
                continue;
            }
            if ($codigo === 8) {
                throw new RuntimeException('El navegador cerró la conexión de control.');
            }
            if ($codigo === 10) {
                continue;
            }
            $mensaje .= $carga;
            if (strlen($mensaje) > self::MAX_MENSAJE) {
                throw new RuntimeException('Mensaje del navegador demasiado grande.');
            }
            if ($fin) {
                return $mensaje;
            }
        }
    }

    private function leerExacto(int $bytes): string
    {
        $datos = '';
        while (strlen($datos) < $bytes) {
            $trozo = fread($this->socket, $bytes - strlen($datos));
            if ($trozo === false || ($trozo === '' && feof($this->socket))) {
                throw new RuntimeException('Se perdió la conexión con el navegador.');
            }
            if ($trozo === '' && stream_get_meta_data($this->socket)['timed_out']) {
                throw new RuntimeException('El navegador dejó un mensaje a medias.');
            }
            $datos .= $trozo;
        }

        return $datos;
    }

    private function escribir(string $texto): void
    {
        $this->escribirTrama(1, $texto);
    }

    private function escribirTrama(int $codigo, string $carga): void
    {
        $trama = self::trama($codigo, $carga);
        for ($escrito = 0; $escrito < strlen($trama); $escrito += $n) {
            $n = fwrite($this->socket, substr($trama, $escrito));
            if ($n === false || $n === 0) {
                throw new RuntimeException('No se pudo escribir en la conexión con el navegador.');
            }
        }
    }

    /**
     * Trama de cliente (siempre enmascarada, RFC 6455 5.3).
     */
    public static function trama(int $codigo, string $carga, ?string $mascara = null): string
    {
        $mascara ??= random_bytes(4);
        $largo = strlen($carga);
        $cabecera = chr(0x80 | $codigo);
        if ($largo < 126) {
            $cabecera .= chr(0x80 | $largo);
        } elseif ($largo < 65536) {
            $cabecera .= chr(0x80 | 126) . pack('n', $largo);
        } else {
            $cabecera .= chr(0x80 | 127) . pack('J', $largo);
        }

        return $cabecera . $mascara . self::xor($carga, $mascara);
    }

    /**
     * Lee una trama con la función de lectura dada.
     *
     * @param Closure(int): string $leer
     * @return array{0: bool, 1: int, 2: string} fin, código y carga
     */
    public static function leerTrama(Closure $leer): array
    {
        $b = $leer(2);
        $fin = (ord($b[0]) & 0x80) !== 0;
        $codigo = ord($b[0]) & 0x0F;
        $enmascarada = (ord($b[1]) & 0x80) !== 0;
        $largo = ord($b[1]) & 0x7F;
        if ($largo === 126) {
            $largo = unpack('n', $leer(2))[1];
        } elseif ($largo === 127) {
            $largo = unpack('J', $leer(8))[1];
        }
        if ($largo < 0 || $largo > self::MAX_MENSAJE) {
            throw new RuntimeException('Trama del navegador con un tamaño no válido.');
        }
        $mascara = $enmascarada ? $leer(4) : '';
        $carga = $largo > 0 ? $leer($largo) : '';
        if ($enmascarada) {
            $carga = self::xor($carga, $mascara);
        }

        return [$fin, $codigo, $carga];
    }

    private static function xor(string $datos, string $mascara): string
    {
        $largo = strlen($datos);

        return $largo === 0 ? '' : $datos ^ substr(str_repeat($mascara, intdiv($largo, 4) + 1), 0, $largo);
    }
}
