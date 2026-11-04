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
}
