<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/** Proveedor de recursos con una web inventada: URL => HTML (o recurso ya hecho). */
final class ProveedorFalso implements ProveedorRecursos
{
    /** @var list<string> URLs pedidas, en orden */
    public array $pedidas = [];

    /** @param array<string, string|RecursoDescargado> $web */
    public function __construct(public array $web = [])
    {
    }

    public static function html(string $url, string $html): RecursoDescargado
    {
        return new RecursoDescargado($url, $url, ResultadoDescarga::Correcto, 200, tipoContenido: 'text/html; charset=utf-8', cuerpo: $html, bytes: strlen($html));
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        $this->pedidas[] = $url;
        $respuesta = $this->web[$url] ?? null;

        return match (true) {
            $respuesta instanceof RecursoDescargado => $respuesta,
            is_string($respuesta) => self::html($url, $respuesta),
            default => new RecursoDescargado($url, $url, ResultadoDescarga::ErrorHttp, 404),
        };
    }
}
