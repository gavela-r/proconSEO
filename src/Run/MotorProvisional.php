<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\TokenCancelacion;
use ProconSEO\Shared\Enum\Fase;

/**
 * Motor provisional mientras la Parte 2 termina el suyo: devuelve TODAS las subcomprobaciones
 * del catálogo como no implementadas (no_verificable / no_ejecutada), que es lo correcto según
 * la especificación. Así el flujo completo se puede probar de punta a punta desde ya.
 *
 * Se sustituirá por el motor de src/Engine/ cuando esté listo (PR de integración).
 */
final class MotorProvisional implements MotorAnalisis
{
    public function __construct(private readonly Catalogo $catalogo)
    {
    }

    public function ejecutar(
        ContextoAnalisis $contexto,
        ProveedorRecursos $proveedor,
        InformadorProgreso $progreso,
        TokenCancelacion $cancelacion,
    ): SalidaMotor {
        $fichas = $this->catalogo->todas();
        $total = count($fichas);
        $resultados = [];

        foreach ($fichas as $i => $ficha) {
            if ($i % 10 === 0) {
                if ($cancelacion->estaCancelado()) {
                    break;
                }
                $progreso->fase(Fase::Comprobaciones, $i, $total, 'Comprobando ' . $ficha->idCriterio);
            }

            $resultados[] = BorradorResultado::noImplementada(
                $ficha->id,
                $ficha->idCriterio,
                $ficha->bloque,
                'El motor de comprobaciones de la Parte 2 todavía no está conectado.',
                $this->catalogo->version(),
            );
        }

        $progreso->fase(Fase::Comprobaciones, count($resultados), $total);

        return new SalidaMotor($resultados);
    }
}
