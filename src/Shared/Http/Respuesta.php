<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Http;

use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Vista;

final class Respuesta
{
    /** Cabeceras de seguridad que lleva toda respuesta. */
    private const CABECERAS_SEGURIDAD = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'",
    ];

    /**
     * @param array<string, string> $cabeceras
     */
    public function __construct(
        public readonly string $cuerpo = '',
        public readonly int $estado = 200,
        public readonly array $cabeceras = [],
    ) {
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($html, $estado, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $datos, int $estado = 200): self
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return new self($json, $estado, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirigir(string $ruta, int $estado = 303): self
    {
        // Solo rutas internas: evita redirecciones abiertas a otros dominios.
        if (!str_starts_with($ruta, '/') || str_starts_with($ruta, '//')) {
            $ruta = '/';
        }

        return new self('', $estado, ['Location' => $ruta]);
    }

    public static function error(ErrorDominio $error, bool $comoJson): self
    {
        if ($comoJson) {
            return self::json($error->comoArray(), $error->estadoHttp);
        }

        return self::html(
            Vista::render('layout/error', ['titulo' => 'Error', 'error' => $error]),
            $error->estadoHttp,
        );
    }

    public function enviar(): void
    {
        http_response_code($this->estado);
        foreach (self::CABECERAS_SEGURIDAD + $this->cabeceras as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        echo $this->cuerpo;
    }
}
