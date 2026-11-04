<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-11: acciones esenciales identificables por nombre y rol (reutiliza los nombres
 * accesibles que también usa ACC-12). Sin navegador solo se valora el HTML inicial.
 */
final class Acciones implements Comprobacion
{
    public function subcomprobaciones(): array
    {
        return ['IA-11.acciones-nombre-rol' => Cobertura::ParcialmenteImplementada];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        if ($id !== 'IA-11.acciones-nombre-rol') {
            throw new RuntimeException("Subcomprobación desconocida: $id");
        }
        $doc = $e->documento();
        $v = $e->resultado($id);
        $formularios = array_filter($doc->formularios, static fn (array $f): bool => $f['metodo'] !== 'ninguno');
        if ($doc->botones === [] && $formularios === []) {
            return $v->noAplicable('La página no tiene botones ni formularios.');
        }
        $sinNombre = array_values(array_filter($doc->botones, static fn (array $b): bool => $b['nombre'] === ''));
        $v->valor(count($doc->botones) . ' botón(es), ' . count($sinNombre) . ' sin nombre.')
            ->limitacion('Solo se revisa el HTML inicial; los controles que añade JavaScript necesitan navegador.');
        if ($sinNombre === []) {
            return $v->correcto();
        }
        $lista = array_map(static fn (array $b): string => '<' . $b['etiqueta'] . ($b['tipo'] !== null ? ' type="' . $b['tipo'] . '"' : '') . '> sin texto ni aria-label', $sinNombre);
        return $v->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
            ->consecuencia('Un asistente con IA o un lector de pantalla no puede saber qué hace un botón sin nombre (enviar, comprar, abrir el chat…).')
            ->correccion('Dar a cada botón un texto visible o, si solo tiene un icono, un aria-label que diga la acción («Abrir chat», «Añadir al carrito»).')
            ->problema(Gravedad::Medio);
    }
}
