<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * URL ya validada y normalizada por ValidadorUrl. Todavía no se ha comprobado a qué IP
 * apunta: para eso está ValidadorDestino.
 */
final class DestinoUrl
{
    public function __construct(
        public readonly string $esquema,
        public readonly string $host,
        public readonly int $puerto,
        public readonly string $rutaYConsulta,
        public readonly bool $hostEsIp,
    ) {
    }

    /** URL canónica: esquema y host en minúsculas, sin puerto por defecto ni fragmento. */
    public function url(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $porDefecto = ($this->esquema === 'https' && $this->puerto === 443) || ($this->esquema === 'http' && $this->puerto === 80);

        return $this->esquema . '://' . $host . ($porDefecto ? '' : ':' . $this->puerto) . $this->rutaYConsulta;
    }

    /** Origen (esquema, host y puerto), p. ej. para construir /robots.txt. */
    public function origen(): string
    {
        return substr($this->url(), 0, strlen($this->url()) - strlen($this->rutaYConsulta));
    }
}
