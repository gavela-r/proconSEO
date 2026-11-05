<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Http;

/**
 * Hace UNA petición de red. Es una interfaz para poder simular respuestas en los tests:
 * la política de seguridad no se puede probar contra un servidor local, porque la propia
 * política lo bloquea.
 */
interface TransporteHttp
{
    public function enviar(PeticionHttp $peticion): RespuestaHttp;

    /**
     * Varias peticiones a la vez (p. ej., PageSpeed móvil y ordenador), cada una con sus
     * propios límites. Solo para destinos distintos o servicios externos: al sitio analizado se
     * le pide de una en una para respetar el ritmo por host.
     *
     * @param list<PeticionHttp> $peticiones
     * @return list<RespuestaHttp> En el mismo orden
     */
    public function enviarVarias(array $peticiones): array;
}
