<?php

declare(strict_types=1);

namespace ProconSEO\Projects;

use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Datos de un proyecto ya validados (especificación 4.1, RF-01, RF-02, RF-03 y RF-06).
 * Solo se construye desde desdeFormulario(), que rechaza cualquier valor no válido.
 */
final class DatosProyecto
{
    public const MAX_NOMBRE = 200;
    public const MAX_CLIENTE = 200;
    public const MAX_URL = 2048;
    public const MAX_DESCRIPCION = 5000;

    private function __construct(
        public readonly string $nombre,
        public readonly ?string $cliente,
        public readonly ?string $urlPrincipal,
        public readonly ?string $descripcion,
        public readonly EstadoProyecto $estado,
        public readonly ?TipoProyecto $tipo,
        public readonly ?Sector $sector,
    ) {
    }

    /**
     * @param array<string, mixed> $entrada Campos enviados por el formulario
     *
     * @throws ErroresFormulario si algún campo no es válido
     */
    public static function desdeFormulario(array $entrada): self
    {
        $errores = [];

        $nombre = self::texto($entrada, 'nombre');
        if ($nombre === null) {
            $errores['nombre'] = 'Escribe un nombre para el proyecto.';
        } elseif (mb_strlen($nombre) > self::MAX_NOMBRE) {
            $errores['nombre'] = 'El nombre no puede tener más de ' . self::MAX_NOMBRE . ' caracteres.';
        }

        $cliente = self::texto($entrada, 'cliente');
        if ($cliente !== null && mb_strlen($cliente) > self::MAX_CLIENTE) {
            $errores['cliente'] = 'El cliente no puede tener más de ' . self::MAX_CLIENTE . ' caracteres.';
        }

        $url = self::texto($entrada, 'url_principal');
        if ($url !== null && ($error = self::errorUrl($url)) !== null) {
            $errores['url_principal'] = $error;
        }

        $descripcion = self::texto($entrada, 'descripcion', false);
        if ($descripcion !== null && mb_strlen($descripcion) > self::MAX_DESCRIPCION) {
            $errores['descripcion'] = 'Las notas no pueden tener más de ' . self::MAX_DESCRIPCION . ' caracteres.';
        }

        $estado = EstadoProyecto::tryFrom(self::texto($entrada, 'estado') ?? EstadoProyecto::Activo->value);
        if ($estado === null) {
            $errores['estado'] = 'Elige un estado de la lista.';
        }

        $valorTipo = self::texto($entrada, 'tipo');
        $tipo = $valorTipo === null ? null : TipoProyecto::tryFrom($valorTipo);
        if ($valorTipo !== null && $tipo === null) {
            $errores['tipo'] = 'Elige un tipo de proyecto de la lista.';
        }

        // El sector solo tiene sentido en "Web de cliente" (RF-06); en otro tipo se descarta.
        $valorSector = $tipo === TipoProyecto::WebCliente ? self::texto($entrada, 'sector') : null;
        $sector = $valorSector === null ? null : Sector::tryFrom($valorSector);
        if ($valorSector !== null && $sector === null) {
            $errores['sector'] = 'Elige un sector de la lista.';
        }

        if ($errores !== [] || $nombre === null || $estado === null) {
            throw new ErroresFormulario($errores);
        }

        return new self($nombre, $cliente, $url, $descripcion, $estado, $tipo, $sector);
    }

    /** Texto recortado; null si viene vacío. Los saltos de línea solo se admiten en campos multilínea. */
    private static function texto(array $entrada, string $campo, bool $unaLinea = true): ?string
    {
        $valor = $entrada[$campo] ?? null;
        if (!is_string($valor)) {
            return null;
        }

        $valor = trim($unaLinea ? (preg_replace('/\s+/u', ' ', $valor) ?? '') : $valor);

        return $valor === '' ? null : $valor;
    }

    /** Solo direcciones http/https completas, sin usuario ni contraseña (como SEG-01). */
    private static function errorUrl(string $url): ?string
    {
        if (strlen($url) > self::MAX_URL) {
            return 'La dirección es demasiado larga.';
        }

        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        if (
            $partes === false
            || !in_array($esquema, ['http', 'https'], true)
            || ($partes['host'] ?? '') === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return 'Escribe una dirección completa que empiece por http:// o https://';
        }

        if (isset($partes['user']) || isset($partes['pass'])) {
            return 'La dirección no puede incluir usuario ni contraseña.';
        }

        return null;
    }
}
