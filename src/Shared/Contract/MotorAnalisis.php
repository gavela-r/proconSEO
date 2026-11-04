<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * El motor de comprobaciones. Lo implementa la Parte 2 (src/Engine/) y lo invoca la Parte 1.
 *
 * - No hace red: pide lo que necesite a ProveedorRecursos.
 * - No escribe en la base de datos: devuelve una SalidaMotor.
 * - Consulta TokenCancelacion entre comprobaciones y, si se cancela, devuelve lo que tenga.
 */
interface MotorAnalisis
{
    public function ejecutar(
        ContextoAnalisis $contexto,
        ProveedorRecursos $proveedor,
        InformadorProgreso $progreso,
        TokenCancelacion $cancelacion,
    ): SalidaMotor;
}
