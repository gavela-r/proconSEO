<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Http;

use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Protección CSRF: toda petición que modifica datos debe llevar el token de la sesión,
 * en el campo de formulario _csrf o en la cabecera X-CSRF-Token.
 */
final class Csrf
{
    private const CLAVE_SESION = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::CLAVE_SESION])) {
            $_SESSION[self::CLAVE_SESION] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CLAVE_SESION];
    }

    public static function verificar(Peticion $peticion): void
    {
        if (in_array($peticion->metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $enviado = (string) ($peticion->cuerpo['_csrf'] ?? $peticion->cabeceras['x-csrf-token'] ?? '');
        $esperado = $_SESSION[self::CLAVE_SESION] ?? '';

        if ($esperado === '' || !hash_equals($esperado, $enviado)) {
            throw new ErrorDominio('csrf_invalido', 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.', 419);
        }
    }
}
