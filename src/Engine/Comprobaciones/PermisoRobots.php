<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-01: permiso de rastreo en robots.txt (RFC 9309).
 *
 * Bloquear a un robot puede ser intencionado (principio 7): sin objetivo declarado es
 * «Decisión a revisar», nunca «Problema». El noindex olvidado en Kit Digital lo valora
 * SEO-01.noindex-desarrollo.
 */
final class PermisoRobots implements Comprobacion
{
    public const BUSCADORES = ['Googlebot', 'bingbot'];
    public const BUSCADORES_IA = ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'];
    public const ENTRENAMIENTO = ['GPTBot', 'ClaudeBot', 'Google-Extended'];

    public function subcomprobaciones(): array
    {
        return [
            'IA-01.robots-recuperado' => Cobertura::Implementada,
            'IA-01.robots-sintaxis' => Cobertura::Implementada,
            'IA-01.permiso-buscadores' => Cobertura::Implementada,
            'IA-01.permiso-buscadores-ia' => Cobertura::Implementada,
            'IA-01.agentes-entrenamiento' => Cobertura::Implementada,
            'IA-01.llms-txt' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'IA-01.robots-recuperado' => $this->recuperado($e),
            'IA-01.robots-sintaxis' => $this->sintaxis($e),
            'IA-01.permiso-buscadores' => $this->permiso($e, $id, self::BUSCADORES, 'los buscadores (Google y Bing)'),
            'IA-01.permiso-buscadores-ia' => $this->permiso($e, $id, self::BUSCADORES_IA, 'los buscadores con IA (ChatGPT, Claude y Perplexity)'),
            'IA-01.agentes-entrenamiento' => $this->entrenamiento($e),
            'IA-01.llms-txt' => $this->llmsTxt($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function recuperado(Entorno $e): BorradorResultado
    {
        $robots = $e->robots();
        $r = $robots['recurso'];
        $evidencia = $e->evidenciaHttp($r, ['content-type', 'retry-after']);
        $v = $e->resultado('IA-01.robots-recuperado')->evidencia($evidencia)->url($r->urlSolicitada);

        return match ($robots['estado']) {
            Entorno::ROBOTS_LEIDO => $v->valor('HTTP ' . $r->estadoHttp . ': robots.txt leído (' . $r->bytes . ' bytes).')->correcto(),
            Entorno::ROBOTS_SIN_RESTRICCIONES => $v
                ->valor('HTTP ' . $r->estadoHttp . ': no hay robots.txt, lo que equivale a «sin restricciones».')
                ->correcto(),
            Entorno::ROBOTS_TEMPORAL => $v
                ->valor('HTTP ' . ($r->estadoHttp ?? '429') . ' al pedir robots.txt.')
                ->consecuencia('Mientras robots.txt responda con un error temporal, Google y otros buscadores pueden dejar de rastrear todo el sitio.')
                ->correccion('Revisar el servidor para que /robots.txt responda 200 (o 404 si no se quiere ninguno).')
                ->limitacion('Un solo análisis no distingue un fallo puntual de uno persistente; no se presupone qué tiene guardado cada buscador.')
                ->problema(Gravedad::Medio),
            default => throw new NoVerificable('No se pudo consultar robots.txt: ' . Entorno::describirDescarga($r) . '.'),
        };
    }

    private function sintaxis(Entorno $e): BorradorResultado
    {
        $robots = $e->robots();
        $v = $e->resultado('IA-01.robots-sintaxis')->url($robots['recurso']->urlSolicitada);
        if ($robots['estado'] !== Entorno::ROBOTS_LEIDO) {
            if ($robots['estado'] === Entorno::ROBOTS_SIN_RESTRICCIONES) {
                return $v->noAplicable('No hay robots.txt (HTTP ' . $robots['recurso']->estadoHttp . ').');
            }
            throw new NoVerificable('robots.txt no se pudo leer: ' . Entorno::describirDescarga($robots['recurso']) . '.');
        }
        $reglas = $robots['reglas'] ?? throw new RuntimeException('Sin reglas');

        $avisos = [];
        foreach (array_slice($reglas->lineasInvalidas, 0, 20) as $l) {
            $avisos[] = 'Línea ' . $l['linea'] . ': ' . $l['texto'];
        }
        if ($reglas->truncado) {
            $avisos[] = 'El fichero supera 500 KiB: los buscadores ignoran lo que hay a partir de ese tamaño.';
        }
        $v->valor(count($reglas->grupos) . ' grupos, ' . count($reglas->sitemaps) . ' sitemaps, '
            . count($reglas->lineasInvalidas) . ' líneas no válidas.');

        if ($avisos === []) {
            return $v->correcto();
        }
        return $v
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $avisos), $robots['recurso']->urlSolicitada))
            ->consecuencia('Los buscadores ignoran las líneas que no entienden: alguna regla puede no aplicarse como se esperaba.')
            ->correccion('Corregir o eliminar las líneas indicadas. Cada regla debe tener la forma «Disallow: /ruta» o «Allow: /ruta» dentro de un grupo «User-agent».')
            ->mejorable();
    }

    /** @param list<string> $agentes */
    private function permiso(Entorno $e, string $id, array $agentes, string $quienes): BorradorResultado
    {
        $estado = [];
        $bloqueados = [];
        foreach ($agentes as $agente) {
            $p = $e->permisoRobots($agente);
            $estado[] = $agente . ': ' . ($p['permitido'] ? 'permitido' : 'bloqueado')
                . ($p['regla'] !== null ? ' (línea ' . $p['linea'] . ', «' . $p['regla'] . '»)' : '');
            if (!$p['permitido']) {
                $bloqueados[] = $agente;
            }
        }

        $v = $e->resultado($id)
            ->valor(implode('; ', $estado))
            ->evidencia($this->evidenciaReglas($e, $estado));

        if ($bloqueados === []) {
            return $v->correcto();
        }
        if ($e->pideNoIndexar()) {
            return $v->limitacion('El bloqueo coincide con el objetivo declarado de no indexar.')->correcto();
        }
        return $v
            ->conclusion(TipoConclusion::Hecho)
            ->consecuencia(ucfirst($quienes) . ' afectados (' . implode(', ', $bloqueados) . ') no pueden leer esta página, así que no la mostrarán ni la citarán con su contenido.')
            ->correccion('Si no es intencionado, quitar o acotar en robots.txt la regla Disallow que afecta a ' . implode(', ', $bloqueados) . '.')
            ->revisionHumana('Bloquear a un robot puede ser una decisión deliberada: hay que confirmarlo con el propietario.')
            ->decisionRevisar();
    }

    private function entrenamiento(Entorno $e): BorradorResultado
    {
        $estado = [];
        foreach (self::ENTRENAMIENTO as $agente) {
            $p = $e->permisoRobots($agente);
            $estado[] = $agente . ': ' . ($p['permitido'] ? 'permitido' : 'bloqueado');
        }
        return $e->resultado('IA-01.agentes-entrenamiento')
            ->explicacion('Estos robots recogen textos para entrenar modelos de IA, no para buscar. Bloquearlos o permitirlos es una decisión del propietario y no cambia la aparición en buscadores.')
            ->valor(implode('; ', $estado))
            ->evidencia($this->evidenciaReglas($e, $estado))
            ->limitacion('Informativo: no se valora a favor ni en contra.')
            ->correcto();
    }

    private function llmsTxt(Entorno $e): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'consultar /llms.txt');
        $origen = Entorno::origen($e->url()) ?? throw new NoVerificable('La URL analizada no es absoluta.');
        $r = $e->obtener($origen . '/llms.txt', 'detección informativa de llms.txt', 'IA-01.llms-txt');
        $v = $e->resultado('IA-01.llms-txt')
            ->url($r->urlSolicitada)
            ->evidencia($e->evidenciaHttp($r, ['content-type']))
            ->limitacion('Informativo: llms.txt no es un estándar adoptado por los buscadores y no se valora.');

        if ($r->esCorrecto() && $r->estadoHttp !== null && $r->estadoHttp < 300) {
            return $v->valor('Existe /llms.txt (' . $r->bytes . ' bytes).')->correcto();
        }
        if ($r->estadoHttp !== null && $r->estadoHttp >= 400 && $r->estadoHttp < 500 && $r->estadoHttp !== 429) {
            return $v->valor('No existe /llms.txt (HTTP ' . $r->estadoHttp . ').')->noAplicable('No existe /llms.txt.');
        }
        throw new NoVerificable('No se pudo consultar /llms.txt: ' . Entorno::describirDescarga($r) . '.');
    }

    /** @param list<string> $estado */
    private function evidenciaReglas(Entorno $e, array $estado): string
    {
        $robots = $e->robots();
        $texto = 'URL evaluada: ' . $e->url() . "\n" . implode("\n", $estado);
        if ($robots['estado'] === Entorno::ROBOTS_SIN_RESTRICCIONES) {
            $texto .= "\nrobots.txt respondió HTTP " . $robots['recurso']->estadoHttp . ' (sin restricciones).';
        }
        return $e->evidencia(TipoEvidencia::Texto, $texto, $robots['recurso']->urlSolicitada);
    }
}
