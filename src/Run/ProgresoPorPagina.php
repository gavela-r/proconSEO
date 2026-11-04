<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Enum\Fase;

/**
 * En el sitio completo el motor se ejecuta una vez por página. Su progreso interno se
 * muestra dentro de la fase de comprobaciones como «Página 3 de 40: …».
 */
final class ProgresoPorPagina implements InformadorProgreso
{
    private int $pagina = 0;

    public function __construct(
        private readonly InformadorProgreso $progreso,
        private readonly int $maxPaginas,
    ) {
    }

    public function siguiente(): void
    {
        $this->pagina++;
    }

    public function fase(Fase $fase, int $hechos, int $total, string $mensaje = ''): void
    {
        $texto = "Página {$this->pagina} (máximo {$this->maxPaginas})" . ($mensaje === '' ? '' : ': ' . $mensaje);
        $this->progreso->fase(Fase::Comprobaciones, $this->pagina, $this->maxPaginas, mb_substr($texto, 0, 500));
    }
}
