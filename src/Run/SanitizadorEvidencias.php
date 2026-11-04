<?php

declare(strict_types=1);

namespace ProconSEO\Run;

/**
 * SEG-09 — Limpia evidencias, motivos y URLs antes de guardarlos.
 *
 * Enmascara cookies, cabeceras de autorización, tokens, contraseñas, claves de API,
 * identificadores de sesión y parámetros sensibles de las URLs. El escapado HTML para
 * mostrarlas es aparte (función e() al imprimir).
 */
final class SanitizadorEvidencias
{
    public const MASCARA = '[OCULTO]';

    /** Nombres de parámetros o campos cuyo valor nunca se guarda. */
    private const NOMBRES_SENSIBLES = 'password|passwd|pwd|contrase(?:ñ|n)a|clave|secret|token|access_token|refresh_token|id_token|api[_-]?key|apikey|auth|authorization|session|sessionid|sid|phpsessid|jsessionid|asp\.net_sessionid|csrf|xsrf|signature|sig|code';

    public function texto(string $texto): string
    {
        $patrones = [
            // Cabeceras HTTP sensibles completas.
            '/^((?:set-)?cookie|authorization|proxy-authorization|x-api-key|x-auth-token)\s*:.*$/mi' => '$1: ' . self::MASCARA,
            // Esquemas de autorización sueltos.
            '/\b(Bearer|Basic|Digest)\s+[A-Za-z0-9\-._~+\/=]{8,}/' => '$1 ' . self::MASCARA,
            // JWT.
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/' => self::MASCARA,
            // nombre=valor o "nombre": "valor" con nombres sensibles (URLs, formularios, JSON).
            '/(?<=[?&;\s"\'{,]|^)((?:' . self::NOMBRES_SENSIBLES . ')["\']?\s*[=:]\s*["\']?)[^&\s"\';,}]+/i' => '$1' . self::MASCARA,
            // value de campos de contraseña en HTML.
            '/(<input\b[^>]*type\s*=\s*["\']?password["\']?[^>]*\bvalue\s*=\s*["\'])[^"\']*/i' => '$1' . self::MASCARA,
            // Claves privadas.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => self::MASCARA,
        ];

        return (string) preg_replace(array_keys($patrones), array_values($patrones), $texto);
    }

    /** URL sin usuario/contraseña ni valores de parámetros sensibles. */
    public function url(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/@?\#]*@#i', '$1', $url);

        return mb_substr($this->texto($url), 0, 2048);
    }
}
