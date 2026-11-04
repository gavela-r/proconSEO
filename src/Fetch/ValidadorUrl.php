<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * SEG-01 — Validación de URL.
 *
 * Acepta solo http/https con un host inequívoco y un puerto permitido. Rechaza credenciales
 * en la URL, formatos de IP ambiguos (decimal, octal, hexadecimal, abreviados), hosts sin
 * dominio y nombres reservados a redes locales. No usa parse_url(): distintas librerías
 * interpretan de forma diferente las URLs raras, así que aquí se parsea de forma estricta
 * y todo lo dudoso se rechaza.
 *
 * Validar la sintaxis no basta: después hay que comprobar la IP real con ValidadorDestino.
 */
final class ValidadorUrl
{
    public const LONGITUD_MAXIMA = 2048;

    /** Sufijos de nombres que solo existen en redes locales. */
    private const SUFIJOS_LOCALES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home.arpa', 'corp'];

    /**
     * @param list<int> $puertosPermitidos Puertos del perfil de ejecución (por defecto 80 y 443)
     */
    public function __construct(private readonly array $puertosPermitidos = [80, 443])
    {
    }

    public function validar(string $entrada): DestinoUrl
    {
        $url = trim($entrada);

        if ($url === '') {
            throw DestinoRechazado::url('url_vacia', 'Introduce una URL.');
        }
        if (strlen($url) > self::LONGITUD_MAXIMA) {
            throw DestinoRechazado::url('url_demasiado_larga', 'La URL es demasiado larga.');
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw DestinoRechazado::url('url_caracteres_no_validos', 'La URL contiene espacios, barras invertidas o caracteres de control.');
        }

        if (!preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $m)) {
            throw DestinoRechazado::url('url_sin_esquema', 'La URL debe empezar por http:// o https://.');
        }
        $esquema = strtolower($m[1]);
        if (!in_array($esquema, ['http', 'https'], true)) {
            throw DestinoRechazado::url('url_esquema_no_permitido', 'Solo se pueden analizar direcciones http:// o https://.');
        }

        if (!preg_match('~^[a-zA-Z]+://([^/?#]*)([^#]*)~', $url, $partes)) {
            throw DestinoRechazado::url('url_mal_formada', 'La URL no tiene un formato válido.');
        }
        [, $autoridad, $resto] = $partes;

        if (str_contains($autoridad, '@')) {
            throw DestinoRechazado::url('url_con_credenciales', 'La URL no puede incluir usuario ni contraseña.');
        }

        [$hostBruto, $puerto] = $this->separarPuerto($autoridad, $esquema);

        if (!in_array($puerto, $this->puertosPermitidos, true)) {
            throw DestinoRechazado::url('url_puerto_no_permitido', "El puerto {$puerto} no está permitido.");
        }

        [$host, $esIp] = $this->normalizarHost($hostBruto);

        return new DestinoUrl($esquema, $host, $puerto, $this->normalizarRuta($resto), $esIp);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function separarPuerto(string $autoridad, string $esquema): array
    {
        $porDefecto = $esquema === 'https' ? 443 : 80;

        if (str_starts_with($autoridad, '[')) {
            if (!preg_match('#^(\[[^\]]+\])(?::(\d*))?$#', $autoridad, $m)) {
                throw DestinoRechazado::url('url_host_no_valido', 'La dirección IPv6 de la URL no es válida.');
            }

            return [$m[1], $this->puerto($m[2] ?? '', $porDefecto)];
        }

        if (substr_count($autoridad, ':') > 1) {
            throw DestinoRechazado::url('url_host_no_valido', 'Las direcciones IPv6 deben ir entre corchetes.');
        }

        $trozos = explode(':', $autoridad, 2);

        return [$trozos[0], $this->puerto($trozos[1] ?? '', $porDefecto)];
    }

    private function puerto(string $texto, int $porDefecto): int
    {
        if ($texto === '') {
            return $porDefecto;
        }
        if (!ctype_digit($texto) || strlen($texto) > 5 || (int) $texto < 1 || (int) $texto > 65535) {
            throw DestinoRechazado::url('url_puerto_no_valido', 'El puerto de la URL no es válido.');
        }

        return (int) $texto;
    }

    /**
     * @return array{0: string, 1: bool} host normalizado y si es una IP literal
     */
    private function normalizarHost(string $host): array
    {
        if ($host === '') {
            throw DestinoRechazado::url('url_sin_host', 'La URL no indica ningún dominio.');
        }

        // IPv6 literal entre corchetes.
        if (str_starts_with($host, '[')) {
            $ip = substr($host, 1, -1);
            if (str_contains($ip, '%') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw DestinoRechazado::url('url_host_no_valido', 'La dirección IPv6 de la URL no es válida.');
            }

            return [(string) inet_ntop((string) inet_pton($ip)), true];
        }

        if (str_contains($host, '%')) {
            throw DestinoRechazado::url('url_host_no_valido', 'El dominio no puede contener caracteres codificados con %.');
        }

        $host = mb_strtolower($host, 'UTF-8');
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        // Cualquier cosa que parezca un número se trata como IPv4 y debe estar en formato
        // decimal estricto (a.b.c.d). Así se rechazan 2130706433, 0177.0.0.1, 0x7f.1, 127.1…
        $etiquetas = explode('.', $host);
        $ultima = end($etiquetas);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) $ultima)) {
            if (!$this->esIpv4Estricta($host)) {
                throw DestinoRechazado::url('url_ip_ambigua', 'La dirección IP de la URL no tiene un formato estándar (a.b.c.d).');
            }

            return [$host, true];
        }

        $ascii = $this->aAscii($host);

        if (strlen($ascii) > 253 || !str_contains($ascii, '.')) {
            throw DestinoRechazado::url('url_host_sin_dominio', 'Indica un dominio completo, por ejemplo www.ejemplo.es.');
        }
        foreach (explode('.', $ascii) as $etiqueta) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $etiqueta)) {
                throw DestinoRechazado::url('url_host_no_valido', 'El dominio de la URL no es válido.');
            }
        }
        foreach (self::SUFIJOS_LOCALES as $sufijo) {
            if ($ascii === $sufijo || str_ends_with($ascii, '.' . $sufijo)) {
                throw DestinoRechazado::url('url_host_local', 'No se pueden analizar direcciones de redes locales.');
            }
        }

        return [$ascii, false];
    }

    private function esIpv4Estricta(string $host): bool
    {
        $partes = explode('.', $host);
        if (count($partes) !== 4) {
            return false;
        }
        foreach ($partes as $parte) {
            if (!preg_match('/^(0|[1-9][0-9]{0,2})$/', $parte) || (int) $parte > 255) {
                return false;
            }
        }

        return true;
    }

    /** Convierte dominios con tildes o eñes (IDN) a su forma ASCII (punycode). */
    private function aAscii(string $host): string
    {
        if (preg_match('/^[\x21-\x7e]+$/', $host)) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            throw DestinoRechazado::url('url_host_no_valido', 'El dominio de la URL no es válido.');
        }

        return strtolower($ascii);
    }

    /** Quita el fragmento y codifica los caracteres no ASCII de la ruta. */
    private function normalizarRuta(string $resto): string
    {
        if ($resto === '') {
            return '/';
        }

        $resto = (string) preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => rawurlencode($m[0]), $resto);

        return str_starts_with($resto, '/') ? $resto : '/' . $resto;
    }
}
