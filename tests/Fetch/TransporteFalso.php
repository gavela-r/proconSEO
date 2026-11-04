<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use ProconSEO\Fetch\Http\PeticionHttp;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\Http\TransporteHttp;

final class TransporteFalso implements TransporteHttp
{
    /** @var list<PeticionHttp> */
    public array $peticiones = [];
    /** @var list<RespuestaHttp> */
    private array $cola = [];
    /** @var (callable(): mixed)|null Se ejecuta tras cada envío */
    public $alEnviar = null;

    public function responder(RespuestaHttp $respuesta): void
    {
        $this->cola[] = $respuesta;
    }

    public function enviar(PeticionHttp $peticion): RespuestaHttp
    {
        $this->peticiones[] = $peticion;
        $respuesta = array_shift($this->cola) ?? new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED, detalleError: 'sin respuesta preparada');
        if ($this->alEnviar !== null) {
            ($this->alEnviar)();
        }

        return $respuesta;
    }
}
