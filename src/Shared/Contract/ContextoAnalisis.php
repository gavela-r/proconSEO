<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Lo que el motor necesita saber de un análisis. Lo construye la Parte 1 y lo recibe la Parte 2.
 */
final class ContextoAnalisis
{
    /**
     * @param list<string>         $hostsAutorizados Hosts exactos del ámbito (sin incluir www ni subdominios automáticamente)
     * @param list<string>         $plataformas      Plataformas de interés declaradas (p. ej. "google", "chatgpt")
     * @param array<string, mixed> $perfil           Parámetros del perfil de ejecución (config/perfiles/v1.json)
     * @param list<InformePageSpeed> $pageSpeed      Informes de PageSpeed de paginaPrincipal (móvil y ordenador).
     *                                               Vacío si no se pidieron, p. ej. en las demás páginas de un sitio completo.
     */
    public function __construct(
        public readonly int $idAnalisis,
        public readonly Alcance $alcance,
        public readonly RecursoDescargado $paginaPrincipal,
        public readonly array $hostsAutorizados,
        public readonly ?string $objetivo = null,
        public readonly array $plataformas = [],
        public readonly ?TipoProyecto $tipoProyecto = null,
        public readonly ?Sector $sector = null,
        public readonly array $perfil = [],
        public readonly array $pageSpeed = [],
    ) {
    }

    /** Informe de PageSpeed para un dispositivo (InformePageSpeed::MOVIL u ORDENADOR), o null si no se pidió. */
    public function pageSpeed(string $dispositivo): ?InformePageSpeed
    {
        foreach ($this->pageSpeed as $informe) {
            if ($informe->dispositivo === $dispositivo) {
                return $informe;
            }
        }

        return null;
    }
}
