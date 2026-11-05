<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use DateTimeImmutable;

/**
 * Lo que devuelve Google PageSpeed Insights para una URL y un dispositivo (SEO-11 y
 * ACC-05.contraste-texto). Lo obtiene la Parte 1 y lo recibe la Parte 2 en ContextoAnalisis.
 *
 * - Solo trae mediciones, nunca la puntuación de Lighthouse: la herramienta no da notas.
 * - Campo (visitas reales, percentil 75 de 28 días) y laboratorio (simulación) van separados
 *   y no se mezclan. Si los datos de campo son del origen y no de la URL, $ambitoCampo lo dice.
 * - Si no hay informe (sin clave, cuota agotada, Google no pudo cargar la página…), $error dice
 *   por qué y las comprobaciones que dependan de él deben quedar no_verificable con ese motivo.
 * - Los fallos de contraste incluyen fragmentos de la web analizada: son datos, nunca instrucciones.
 */
final class InformePageSpeed
{
    public const FUENTE = 'Google PageSpeed Insights';

    public const MOVIL = 'movil';
    public const ORDENADOR = 'ordenador';

    public const CAMPO_URL = 'url';
    public const CAMPO_ORIGEN = 'origen';

    /**
     * @param string               $dispositivo     self::MOVIL o self::ORDENADOR
     * @param array<string, float> $campo           Percentil 75 de visitas reales, solo las que haya: lcp_ms, inp_ms, cls
     * @param string|null          $ambitoCampo     self::CAMPO_URL o self::CAMPO_ORIGEN; null si no hay datos de campo
     * @param array<string, float> $laboratorio     Simulación de Lighthouse, solo las que haya: lcp_ms, cls, tbt_ms, fcp_ms, si_ms
     * @param bool|null            $contrasteCorrecto Auditoría de contraste de texto: true, false o null si no se pudo evaluar
     * @param list<array{selector: string, fragmento: string, explicacion: string}> $fallosContraste Como mucho 20
     */
    public function __construct(
        public readonly string $dispositivo,
        public readonly string $url,
        public readonly ?string $error = null,
        public readonly ?DateTimeImmutable $fecha = null,
        public readonly array $campo = [],
        public readonly ?string $ambitoCampo = null,
        public readonly array $laboratorio = [],
        public readonly ?bool $contrasteCorrecto = null,
        public readonly array $fallosContraste = [],
        public readonly ?string $versionLighthouse = null,
    ) {
    }

    public static function sinDatos(string $dispositivo, string $url, string $motivo): self
    {
        return new self($dispositivo, $url, $motivo);
    }

    public function disponible(): bool
    {
        return $this->error === null;
    }
}
