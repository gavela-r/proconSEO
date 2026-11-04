<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use DateTimeImmutable;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use RuntimeException;

/**
 * Carga los recursos de ejemplo de tests/Fixtures/respuestas/ como si los hubiera descargado
 * la Parte 1. Cada respuesta es un JSON que apunta a su cuerpo (página, robots.txt o sitemap)
 * y declara los resultados esperados de las comprobaciones más claras.
 */
final class Fixtures
{
    public const DIR = __DIR__ . '/../../Fixtures';

    /** Momento fijo de descarga, para que los tests sean reproducibles. */
    public const FECHA = '2026-11-04T10:00:00+01:00';

    /** @return list<string> */
    public static function nombres(): array
    {
        $nombres = array_map(
            static fn (string $f): string => basename($f, '.json'),
            glob(self::DIR . '/respuestas/*.json') ?: [],
        );
        sort($nombres);
        return $nombres;
    }

    /** @return array<string, mixed> Datos del JSON tal cual. */
    public static function datos(string $nombre): array
    {
        $fichero = self::DIR . "/respuestas/$nombre.json";
        if (!is_file($fichero)) {
            throw new RuntimeException("No existe la respuesta de ejemplo «{$nombre}».");
        }
        $datos = json_decode((string) file_get_contents($fichero), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($datos)) {
            throw new RuntimeException("$fichero no es un objeto JSON.");
        }
        return $datos;
    }

    public static function cuerpo(string $ruta): string
    {
        $fichero = self::DIR . '/' . $ruta;
        if (!is_file($fichero)) {
            throw new RuntimeException("No existe el cuerpo de ejemplo «{$ruta}».");
        }
        return (string) file_get_contents($fichero);
    }

    public static function recurso(string $nombre): RecursoDescargado
    {
        $d = self::datos($nombre);
        $cuerpo = $d['cuerpo'] === null ? '' : self::cuerpo($d['cuerpo']);

        return new RecursoDescargado(
            urlSolicitada: $d['url_solicitada'],
            urlFinal: $d['url_final'],
            resultado: ResultadoDescarga::from($d['resultado']),
            estadoHttp: $d['estado_http'],
            redirecciones: $d['redirecciones'],
            cabeceras: $d['cabeceras'],
            tipoContenido: $d['tipo_contenido'],
            cuerpo: $cuerpo,
            truncado: false,
            bytes: strlen($cuerpo),
            tlsVerificado: $d['tls_verificado'],
            motivo: $d['motivo'],
            descargadoEn: new DateTimeImmutable(self::FECHA),
        );
    }

    /**
     * Contexto de análisis con la respuesta como página principal y el tipo de proyecto,
     * sector y objetivo que declara el JSON.
     *
     * @param list<string>|null $hosts Hosts del ámbito; por defecto, el de la URL solicitada
     */
    public static function contexto(string $nombre, Alcance $alcance = Alcance::UrlIndividual, ?array $hosts = null): ContextoAnalisis
    {
        $d = self::datos($nombre);
        $c = $d['contexto'];

        return new ContextoAnalisis(
            idAnalisis: 1,
            alcance: $alcance,
            paginaPrincipal: self::recurso($nombre),
            hostsAutorizados: $hosts ?? [(string) parse_url($d['url_solicitada'], PHP_URL_HOST)],
            objetivo: $c['objetivo'],
            tipoProyecto: $c['tipo_proyecto'] === null ? null : TipoProyecto::from($c['tipo_proyecto']),
            sector: $c['sector'] === null ? null : Sector::from($c['sector']),
        );
    }

    /** @return array<string, string> id de subcomprobación => estado esperado */
    public static function esperado(string $nombre): array
    {
        return self::datos($nombre)['esperado'];
    }
}
