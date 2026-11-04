<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Contract\TokenCancelacion;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\Fase;

/**
 * Progreso y cancelación de un análisis guardados en la base de datos, para que la pantalla
 * de progreso los lea mientras el worker trabaja.
 */
final class ProgresoBd implements InformadorProgreso, TokenCancelacion
{
    /** No se consulta la base de datos más de una vez por este intervalo (segundos). */
    private const INTERVALO_CANCELACION = 1.0;

    private float $ultimaConsulta = 0.0;
    private bool $cancelado = false;

    public function __construct(
        private readonly RepositorioAnalisis $repositorio,
        private readonly int $analisisId,
    ) {
    }

    public function fase(Fase $fase, int $hechos, int $total, string $mensaje = ''): void
    {
        $this->repositorio->marcarFase($this->analisisId, $fase);
        $this->repositorio->actualizarFase($this->analisisId, $fase, EstadoFase::EnCurso, $hechos, $total, $mensaje);
    }

    public function estaCancelado(): bool
    {
        if ($this->cancelado) {
            return true;
        }
        $ahora = microtime(true);
        if ($ahora - $this->ultimaConsulta >= self::INTERVALO_CANCELACION) {
            $this->ultimaConsulta = $ahora;
            $this->cancelado = $this->repositorio->cancelacionPedida($this->analisisId);
        }

        return $this->cancelado;
    }
}
