<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Comprobaciones que comparan las páginas de un sitio completo entre sí. Lo implementa el
 * motor de la Parte 2 (junto a MotorAnalisis) y lo invoca la Parte 1 al terminar el recorrido.
 *
 * - En cada página, MotorAnalisis::ejecutar devuelve estas subcomprobaciones como no_verificable
 *   («se valora para todo el sitio») y un ResumenPagina en SalidaMotor::$resumen.
 * - Al final, consolidarSitio recibe los resúmenes y devuelve el resultado definitivo de cada
 *   una, una sola vez para todo el análisis. La Parte 1 guarda estos y no los de cada página.
 * - Igual que ejecutar: no hace red ni escribe en la base de datos.
 */
interface ConsolidadorSitio
{
    /**
     * IDs de las subcomprobaciones que se valoran para todo el sitio.
     *
     * @return list<string>
     */
    public function subcomprobacionesDeSitio(): array;

    /**
     * @param ContextoAnalisis    $contexto   El de la URL indicada (alcance, ámbito y perfil)
     * @param list<ResumenPagina> $resumenes  Uno por página analizada, en el orden del recorrido
     * @return SalidaMotor Un resultado por cada id de subcomprobacionesDeSitio(), sus evidencias y causas
     */
    public function consolidarSitio(
        ContextoAnalisis $contexto,
        array $resumenes,
        InformadorProgreso $progreso,
        TokenCancelacion $cancelacion,
    ): SalidaMotor;
}
