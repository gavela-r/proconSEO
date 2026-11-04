<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Enum\Alcance;

/**
 * Ejecuta el motor sobre una respuesta de ejemplo y da acceso a los resultados por id.
 */
final class Ejecucion
{
    /** @var array<string, BorradorResultado> */
    public readonly array $porId;

    private function __construct(public readonly SalidaMotor $salida, public readonly ProveedorFalso $proveedor)
    {
        $porId = [];
        foreach ($salida->resultados as $r) {
            $porId[$r->idComprobacion] = $r;
        }
        $this->porId = $porId;
    }

    /**
     * @param array<string, string|\ProconSEO\Shared\Contract\RecursoDescargado> $otras URL => respuesta de ejemplo.
     *        Si no se indica robots.txt del origen, se usa «robots-404» (sin restricciones).
     */
    public static function de(string $fixture, array $otras = [], Alcance $alcance = Alcance::UrlIndividual, ?ContextoAnalisis $contexto = null): self
    {
        $contexto ??= Fixtures::contexto($fixture, $alcance);
        $pagina = $contexto->paginaPrincipal;
        $origen = Entorno::origen($pagina->urlFinal) ?? Entorno::origen($pagina->urlSolicitada);
        if ($origen !== null) {
            $otras += [$origen . '/robots.txt' => 'robots-404'];
        }
        $proveedor = new ProveedorFalso($otras);
        $salida = Motor::v1()->ejecutar($contexto, $proveedor, new ProgresoFalso(), CancelacionFalsa::nunca());
        return new self($salida, $proveedor);
    }

    public function resultado(string $id): BorradorResultado
    {
        return $this->porId[$id] ?? throw new \RuntimeException("No hay resultado para $id");
    }

    public function estado(string $id): string
    {
        return $this->resultado($id)->estado->value;
    }
}
