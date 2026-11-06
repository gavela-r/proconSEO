<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

/**
 * Lo que mide el navegador aislado en una página. Solo datos: la lectura (correcto, problema…)
 * la hace LecturaProvisional y, cuando se integre en el motor, las comprobaciones de la Parte 2.
 *
 * Si $error no es null, la página no se pudo pintar y las mediciones están vacías: todo lo que
 * dependa de ellas es no_verificable, nunca correcto.
 */
final class PaginaRenderizada
{
    /**
     * @param array{html: string, truncado: bool, url: string}|null $dom DOM tras ejecutar JavaScript
     * @param array{muestras: list<array<string, mixed>>, tapados: int, fueraDeVista: int, inactivos: int, elementos: int}|null $contraste
     * @param array{enfocables: int, pasos: list<array<string, mixed>>, indicadores: list<?bool>, vueltaCompleta: bool}|null $foco
     * @param array{ancho: int, total: int, culpables: list<array{selector: string, etiqueta: string, derecha: int}>}|null $reflow
     * @param list<array{url: string, tipo: string, permitida: bool, motivo: string}> $peticiones
     * @param list<string> $limitaciones
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $error = null,
        public readonly ?string $htmlInicial = null,
        public readonly ?array $dom = null,
        public readonly ?int $palabrasVisibles = null,
        public readonly ?array $contraste = null,
        public readonly ?array $foco = null,
        public readonly ?array $reflow = null,
        public readonly array $peticiones = [],
        public readonly array $limitaciones = [],
        public readonly float $segundos = 0.0,
    ) {
    }

    public function esCorrecta(): bool
    {
        return $this->error === null;
    }
}
