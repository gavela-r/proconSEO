<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Engine\Valoracion;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-02: directivas de indexación y de fragmentos (meta robots y X-Robots-Tag).
 *
 * Rastrear, indexar y mostrar fragmentos son cosas distintas. Un noindex sin objetivo
 * declarado es «Decisión a revisar»: puede ser deliberado.
 */
final class DirectivasIndexacion implements Comprobacion
{
    /** Robots cuyas directivas afectan a los buscadores que se valoran. */
    private const AGENTES = ['robots', 'googlebot', 'bingbot', 'msnbot'];

    public function subcomprobaciones(): array
    {
        return [
            'IA-02.meta-robots' => Cobertura::Implementada,
            'IA-02.x-robots-tag' => Cobertura::Implementada,
            'IA-02.fragmentos' => Cobertura::Implementada,
            'IA-02.coherencia-directivas' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'IA-02.meta-robots' => $this->meta($e),
            'IA-02.x-robots-tag' => $this->cabecera($e),
            'IA-02.fragmentos' => $this->fragmentos($e),
            'IA-02.coherencia-directivas' => $this->coherencia($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Directivas de la meta robots, por robot.
     *
     * @return array<string, list<string>>
     */
    public static function directivasMeta(Entorno $e): array
    {
        return array_intersect_key($e->documento()->directivasRobots(), array_flip(self::AGENTES));
    }

    /**
     * Directivas de la cabecera X-Robots-Tag, por robot ("robots" si no indica ninguno).
     * Formato: "noindex, nofollow" o "googlebot: noindex" (varias cabeceras llegan unidas por comas).
     *
     * @return array<string, list<string>>
     */
    public static function directivasCabecera(Entorno $e): array
    {
        // Las cabeceras de una respuesta de error (403, 500…) no son las de la página.
        $e->exigePagina();
        $valor = $e->pagina()->cabecera('x-robots-tag');
        if ($valor === null || trim($valor) === '') {
            return [];
        }
        $directivas = [];
        $agente = 'robots';
        foreach (preg_split('/\s*,\s*/', strtolower(trim($valor))) ?: [] as $parte) {
            if (preg_match('/^([a-z0-9_\-]+)\s*:\s*(.*)$/', $parte, $m) === 1 && !self::esDirectivaConValor($m[1])) {
                $agente = $m[1];
                $parte = $m[2];
            }
            if ($parte !== '') {
                $directivas[$agente][] = $parte;
            }
        }
        return array_intersect_key($directivas, array_flip(self::AGENTES));
    }

    /** Origen de cada noindex encontrado («meta robots», «cabecera X-Robots-Tag (googlebot)»…). */
    public static function fuentesNoindex(Entorno $e): array
    {
        $fuentes = [];
        foreach (self::directivasMeta($e) as $agente => $lista) {
            if (array_intersect($lista, ['noindex', 'none']) !== []) {
                $fuentes[] = 'meta ' . $agente . ' «' . implode(', ', $lista) . '»';
            }
        }
        foreach (self::directivasCabecera($e) as $agente => $lista) {
            if (array_intersect($lista, ['noindex', 'none']) !== []) {
                $fuentes[] = 'cabecera X-Robots-Tag' . ($agente === 'robots' ? '' : ' (' . $agente . ')') . ' «' . implode(', ', $lista) . '»';
            }
        }
        return $fuentes;
    }

    private function meta(Entorno $e): BorradorResultado
    {
        $directivas = self::directivasMeta($e);
        $v = $e->resultado('IA-02.meta-robots');
        if ($directivas === []) {
            return $v->valor('No hay meta robots: la página se puede indexar.')->correcto();
        }
        $texto = [];
        foreach ($directivas as $agente => $lista) {
            $texto[] = '<meta name="' . $agente . '" content="' . implode(', ', $lista) . '">';
        }
        $v->valor(implode(' ', $texto))
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $texto)));

        return $this->valorarNoindex($e, $v, self::tieneNoindex($directivas), 'la meta robots');
    }

    private function cabecera(Entorno $e): BorradorResultado
    {
        $directivas = self::directivasCabecera($e);
        $v = $e->resultado('IA-02.x-robots-tag');
        if ($directivas === []) {
            return $v->valor('No hay cabecera X-Robots-Tag.')->correcto();
        }
        $v->valor('X-Robots-Tag: ' . (string) $e->pagina()->cabecera('x-robots-tag'))
            ->evidencia($e->evidenciaHttp($e->pagina(), ['x-robots-tag']));

        return $this->valorarNoindex($e, $v, self::tieneNoindex($directivas), 'la cabecera X-Robots-Tag');
    }

    private function valorarNoindex(Entorno $e, Valoracion $v, bool $noindex, string $donde): BorradorResultado
    {
        if (!$noindex) {
            return $v->correcto();
        }
        if ($e->pideNoIndexar()) {
            return $v->limitacion('Coincide con el objetivo declarado de no indexar.')->correcto();
        }
        return $v
            ->consecuencia('Con noindex, la página no aparece en Google ni en Bing, ni en las respuestas de IA que se basan en ellos.')
            ->correccion('Si la página debe aparecer en buscadores, quitar «noindex» de ' . $donde . '.')
            ->correccionCms([
                'wordpress' => 'Ajustes > Lectura: desmarcar «Disuadir a los motores de búsqueda de indexar este sitio». Si usa Yoast o Rank Math, revisar también la opción «Permitir que los motores de búsqueda muestren esta página».',
                'prestashop' => 'Revisar el módulo SEO o el tema: PrestaShop no añade noindex por defecto a las páginas públicas.',
            ])
            ->revisionHumana('Un noindex puede ser deliberado (página privada, en pruebas…): hay que confirmarlo con el propietario.')
            ->decisionRevisar();
    }

    private function fragmentos(Entorno $e): BorradorResultado
    {
        $restricciones = [];
        foreach ([self::directivasMeta($e), self::directivasCabecera($e)] as $origen => $porAgente) {
            foreach ($porAgente as $agente => $lista) {
                foreach ($lista as $d) {
                    if (in_array(str_replace(' ', '', $d), ['nosnippet', 'max-snippet:0', 'max-image-preview:none'], true)) {
                        $restricciones[] = ($origen === 0 ? 'meta ' : 'X-Robots-Tag ') . $agente . ': ' . $d;
                    }
                }
            }
        }
        $xp = new DOMXPath($e->documento()->dom);
        $marcados = $xp->query('//*[@data-nosnippet]');
        $n = $marcados === false ? 0 : $marcados->length;
        if ($n > 0) {
            $restricciones[] = $n . ' elemento(s) con data-nosnippet';
        }

        $v = $e->resultado('IA-02.fragmentos');
        if ($restricciones === []) {
            return $v->valor('Sin restricciones de fragmentos.')->correcto();
        }
        return $v
            ->valor(implode('; ', $restricciones))
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $restricciones)))
            ->consecuencia('Los buscadores y las respuestas con IA no pueden mostrar o citar el texto restringido, aunque la página siga indexada.')
            ->correccion('Si no es intencionado, quitar nosnippet, max-snippet o data-nosnippet de lo que sí se quiere que se cite.')
            ->revisionHumana('Limitar los fragmentos puede ser deliberado: hay que confirmarlo.')
            ->decisionRevisar();
    }

    private function coherencia(Entorno $e): BorradorResultado
    {
        $meta = self::directivasMeta($e);
        $cabecera = self::directivasCabecera($e);
        $v = $e->resultado('IA-02.coherencia-directivas');
        $hayNoindex = self::tieneNoindex($meta) || self::tieneNoindex($cabecera);

        $contradicciones = [];
        if (self::tieneNoindex($meta) !== self::tieneNoindex($cabecera)
            && (self::dice($meta, 'index') || self::dice($cabecera, 'index') || self::dice($meta, 'all') || self::dice($cabecera, 'all'))) {
            $contradicciones[] = 'La meta robots y la cabecera X-Robots-Tag dicen cosas distintas sobre la indexación.';
        }

        if ($hayNoindex) {
            try {
                $permiso = $e->permisoRobots('Googlebot');
            } catch (NoVerificable $nv) {
                throw new NoVerificable('Hay noindex, pero no se pudo comprobar si robots.txt impide verlo. ' . $nv->getMessage());
            }
            if (!$permiso['permitido']) {
                return $v
                    ->valor('noindex en la página y robots.txt bloquea a Googlebot (' . $permiso['regla'] . ').')
                    ->evidencia($e->evidencia(TipoEvidencia::Texto, 'noindex: ' . implode('; ', self::fuentesNoindex($e)) . "\nrobots.txt línea " . $permiso['linea'] . ': ' . $permiso['regla']))
                    ->consecuencia('Google no puede leer la página y, por tanto, no ve el noindex: la URL puede seguir apareciendo en resultados, sin descripción.')
                    ->correccion('Elegir una sola vía: para sacarla de Google, permitir el rastreo en robots.txt y mantener el noindex; para no rastrearla, quitar el noindex.')
                    ->problema(Gravedad::Medio);
            }
        }

        if ($contradicciones !== []) {
            return $v->valor(implode(' ', $contradicciones))
                ->correccion('Dejar la misma directiva en la meta y en la cabecera, o usar solo una de las dos.')
                ->mejorable();
        }
        return $v->valor('Las directivas no se contradicen.')->correcto();
    }

    /** @param array<string, list<string>> $porAgente */
    private static function tieneNoindex(array $porAgente): bool
    {
        foreach ($porAgente as $lista) {
            if (array_intersect($lista, ['noindex', 'none']) !== []) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, list<string>> $porAgente */
    private static function dice(array $porAgente, string $directiva): bool
    {
        foreach ($porAgente as $lista) {
            if (in_array($directiva, $lista, true)) {
                return true;
            }
        }
        return false;
    }

    private static function esDirectivaConValor(string $clave): bool
    {
        return in_array($clave, ['max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after', 'indexifembedded'], true);
    }
}
