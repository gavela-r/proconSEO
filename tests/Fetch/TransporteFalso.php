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
    /** @var array<string, RespuestaHttp> Respuestas fijas por URL; tienen prioridad sobre la cola */
    public array $porUrl = [];
    /** @var (callable(): mixed)|null Se ejecuta tras cada envío */
    public $alEnviar = null;

    public function responder(RespuestaHttp $respuesta): void
    {
        $this->cola[] = $respuesta;
    }

    /** @var int Cuántas veces se pidieron varias a la vez */
    public int $tandas = 0;

    public function enviarVarias(array $peticiones): array
    {
        $this->tandas++;

        return array_map($this->enviar(...), array_values($peticiones));
    }

    public function enviar(PeticionHttp $peticion): RespuestaHttp
    {
        $this->peticiones[] = $peticion;
        $respuesta = $this->porUrl[$peticion->destino->url->url()] ?? array_shift($this->cola) ?? new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED, detalleError: 'sin respuesta preparada');
        if ($this->alEnviar !== null) {
            ($this->alEnviar)();
        }

        return $respuesta;
    }
}
