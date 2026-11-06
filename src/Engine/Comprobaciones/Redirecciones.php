<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-02: URL solicitada frente a final, cadenas y bucles de redirección, canonical y
 * variantes de la misma URL.
 */
final class Redirecciones implements Comprobacion
{
    /** Redirecciones que sigue Googlebot como máximo (perfil v1). */
    public const MAXIMO_SALTOS = 10;

    public function subcomprobaciones(): array
    {
        return [
            'SEO-02.url-final' => Cobertura::Implementada,
            'SEO-02.cadena-redirecciones' => Cobertura::Implementada,
            'SEO-02.bucle-redirecciones' => Cobertura::Implementada,
            'SEO-02.canonical-coherente' => Cobertura::Implementada,
            'SEO-02.variantes-host' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'SEO-02.url-final' => $this->urlFinal($entorno),
            'SEO-02.cadena-redirecciones' => $this->cadena($entorno),
            'SEO-02.bucle-redirecciones' => $this->bucle($entorno),
            'SEO-02.canonical-coherente' => $this->canonical($entorno),
            'SEO-02.variantes-host' => $this->variantes($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function urlFinal(Entorno $e): BorradorResultado
    {
        $p = $e->pagina();
        $v = $e->resultado('SEO-02.url-final')->url($p->urlSolicitada);

        if ($p->resultado === ResultadoDescarga::FueraDeAmbito && $p->redirecciones !== []) {
            $ultimo = $p->redirecciones[array_key_last($p->redirecciones)];
            return $v->valor('La URL redirige a ' . ($ultimo['destino'] ?? '—') . ', fuera del ámbito autorizado: no se ha seguido.')
                ->evidencia($e->evidenciaHttp($p))
                ->consecuencia('No se ha podido analizar la página de destino.')
                ->correccion('Si la redirección es correcta, volver a lanzar el análisis con la URL de destino o añadir ese dominio al ámbito.')
                ->revisionHumana('Hay que confirmar si la redirección a otro dominio es la esperada.')
                ->decisionRevisar();
        }
        $e->exigeRespuesta();

        if (Url::sinFragmento($p->urlSolicitada) === Url::sinFragmento($p->urlFinal)) {
            return $v->valor('La URL final coincide con la solicitada.')->correcto();
        }
        return $v->valor('Se solicitó ' . $p->urlSolicitada . ' y la URL final es ' . $p->urlFinal . ' (' . count($p->redirecciones) . ' redirección(es)).')
            ->evidencia($e->evidenciaHttp($p))
            ->correcto();
    }

    private function cadena(Entorno $e): BorradorResultado
    {
        $p = $e->pagina();
        $saltos = count($p->redirecciones);
        $v = $e->resultado('SEO-02.cadena-redirecciones')->url($p->urlSolicitada);
        if ($saltos === 0) {
            if ($p->estadoHttp === null) {
                throw new NoVerificable('No hubo respuesta: ' . Entorno::describirDescarga($p) . '.');
            }
            return $v->noAplicable('La URL no redirige.');
        }

        $tipos = array_map(static fn (array $s): string => (string) $s['estado'], $p->redirecciones);
        $v->valor($saltos . ' salto(s): ' . implode(' → ', $tipos))->evidencia($e->evidenciaHttp($p));
        $temporales = array_intersect($tipos, ['302', '303', '307']);

        if ($saltos >= 2) {
            return $v
                ->consecuencia('Cada salto añade espera para el usuario y trabajo para el buscador; con muchos, Google deja de seguirlos.')
                ->correccion('Redirigir directamente de ' . $p->urlSolicitada . ' a ' . $p->urlFinal . ' en un solo salto 301.')
                ->problema(Gravedad::Bajo);
        }
        if ($temporales !== []) {
            return $v
                ->consecuencia('Una redirección temporal (' . implode(', ', $temporales) . ') indica a Google que la URL original volverá: puede seguir mostrándola en lugar de la final.')
                ->correccion('Si el cambio es definitivo, usar 301 o 308.')
                ->revisionHumana('Una redirección temporal puede ser intencionada (campañas, mantenimiento).')
                ->decisionRevisar();
        }
        return $v->correcto();
    }

    private function bucle(Entorno $e): BorradorResultado
    {
        $p = $e->pagina();
        $v = $e->resultado('SEO-02.bucle-redirecciones')->url($p->urlSolicitada);
        if ($p->redirecciones === []) {
            if ($p->estadoHttp === null) {
                throw new NoVerificable('No hubo respuesta: ' . Entorno::describirDescarga($p) . '.');
            }
            return $v->noAplicable('La URL no redirige.');
        }

        $vistas = [];
        foreach ($p->redirecciones as $salto) {
            $vistas[] = Url::sinFragmento($salto['url']);
            $destino = $salto['destino'] === null ? null : Url::sinFragmento($salto['destino']);
            if ($destino !== null && in_array($destino, $vistas, true)) {
                return $v->valor('Bucle: ' . $salto['url'] . ' vuelve a ' . $salto['destino'] . '.')
                    ->evidencia($e->evidenciaHttp($p))
                    ->consecuencia('Ni los usuarios ni los buscadores llegan nunca a la página.')
                    ->correccion('Revisar las reglas de redirección del servidor o del CMS para que terminen en una URL que responda 200.')
                    ->problema(Gravedad::Critico);
            }
        }
        if (count($p->redirecciones) >= self::MAXIMO_SALTOS) {
            return $v->valor(count($p->redirecciones) . ' redirecciones seguidas: se alcanzó el límite.')
                ->evidencia($e->evidenciaHttp($p))
                ->consecuencia('Googlebot deja de seguir a partir de 10 redirecciones: la página final no se indexa.')
                ->correccion('Reducir la cadena a un único salto.')
                ->problema(Gravedad::Alto);
        }
        return $v->valor('Sin bucles: ' . count($p->redirecciones) . ' salto(s) hasta una URL final.')->correcto();
    }

    private function canonical(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-02.canonical-coherente');
        if ($doc->canonicos === []) {
            return $v->noAplicable('La página no declara canonical (ver IA-08.canonical-declarado).');
        }
        $canonico = $doc->canonicos[0];
        $v->valor('canonical: ' . $canonico)->elemento('<link rel="canonical" href="' . $canonico . '">');

        if (Url::sinFragmento($canonico) === Url::sinFragmento($e->url())) {
            return $v->valor('El canonical apunta a la propia página.')->correcto();
        }

        $e->exigeAlcance(Alcance::UrlContexto, 'comprobar la URL canónica');
        $r = $e->obtener($canonico, 'URL canónica declarada', 'SEO-02.canonical-coherente');
        $v->evidencia($e->evidenciaHttp($r, ['x-robots-tag']));

        if ($r->estadoHttp === null || $r->resultado === ResultadoDescarga::FueraDeAmbito) {
            throw new NoVerificable('No se pudo consultar la URL canónica: ' . Entorno::describirDescarga($r) . '.');
        }
        if ($r->redirecciones !== []) {
            return $v->valor('El canonical (' . $canonico . ') redirige a ' . $r->urlFinal . '.')
                ->consecuencia('Google recibe señales contradictorias sobre cuál es la URL principal y puede ignorar el canonical.')
                ->correccion('Hacer que el canonical apunte directamente a la URL final (' . $r->urlFinal . ').')
                ->problema(Gravedad::Medio);
        }
        if ($r->estadoHttp >= 400) {
            return $v->valor('El canonical (' . $canonico . ') responde HTTP ' . $r->estadoHttp . '.')
                ->consecuencia('El canonical apunta a una página que no existe: Google lo ignora y elige otra URL por su cuenta.')
                ->correccion('Corregir el canonical para que apunte a una URL que responda 200.')
                ->problema(Gravedad::Medio);
        }
        $noindex = $r->cabecera('x-robots-tag') !== null && str_contains(strtolower((string) $r->cabecera('x-robots-tag')), 'noindex');
        if ($noindex) {
            return $v->valor('El canonical apunta a una página con noindex.')
                ->consecuencia('Se pide a Google que indexe una URL que a la vez dice que no se indexe.')
                ->correccion('Apuntar el canonical a una URL indexable.')
                ->problema(Gravedad::Medio);
        }
        return $v->valor('El canonical apunta a otra URL (' . $canonico . ') que responde ' . $r->estadoHttp . ' sin redirigir.')
            ->conclusion(TipoConclusion::Inferencia)
            ->limitacion('No se ha comprobado la meta robots de la página canónica.')
            ->correcto();
    }

    private function variantes(Entorno $e): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'comprobar las variantes de la URL');
        $e->exigePagina();
        $final = Url::sinFragmento($e->url());
        $partes = parse_url($final);
        $host = $partes['host'] ?? '';
        $resto = ($partes['path'] ?? '/') . (isset($partes['query']) ? '?' . $partes['query'] : '');
        $otroHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;

        $variantes = [];
        foreach (['http', 'https'] as $esquema) {
            foreach ([$host, $otroHost] as $h) {
                $url = $esquema . '://' . $h . $resto;
                if ($url !== $final) {
                    $variantes[] = $url;
                }
            }
        }

        $correctas = [];
        $duplicadas = [];
        $sinComprobar = [];
        foreach ($variantes as $url) {
            $r = $e->obtener($url, 'variante de la URL analizada', 'SEO-02.variantes-host');
            if ($r->estadoHttp === null || !in_array($r->resultado, [ResultadoDescarga::Correcto, ResultadoDescarga::ErrorHttp], true)) {
                $sinComprobar[] = $url . ' (' . $r->resultado->etiqueta() . ')';
            } elseif (Url::sinFragmento($r->urlFinal) === $final) {
                $correctas[] = $url;
            } elseif ($r->estadoHttp < 300) {
                $duplicadas[] = $url . ' (responde ' . $r->estadoHttp . ' sin redirigir a ' . $final . ')';
            } else {
                $sinComprobar[] = $url . ' (HTTP ' . $r->estadoHttp . ')';
            }
        }

        $detalle = array_merge(
            array_map(static fn (string $u): string => 'Redirige a la versión preferida: ' . $u, $correctas),
            array_map(static fn (string $u): string => 'Duplicada: ' . $u, $duplicadas),
            array_map(static fn (string $u): string => 'Sin comprobar: ' . $u, $sinComprobar),
        );
        $v = $e->resultado('SEO-02.variantes-host')
            ->valor(count($correctas) . ' variante(s) redirigen, ' . count($duplicadas) . ' duplicada(s), ' . count($sinComprobar) . ' sin comprobar.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $detalle)));

        if ($duplicadas !== []) {
            return $v->elemento($duplicadas[0])
                ->consecuencia('La misma página se puede ver en varias direcciones: Google tiene que adivinar cuál mostrar y reparte las señales entre ellas.')
                ->correccion('Redirigir con 301 todas las variantes (http/https, con y sin www) a ' . $final . '.')
                ->mejorable();
        }
        if ($correctas === []) {
            throw new NoVerificable('No se pudo comprobar ninguna variante: ' . implode('; ', $sinComprobar) . '. Si la web usa también ' . $otroHost . ', hay que añadirlo al ámbito.');
        }
        if ($sinComprobar !== []) {
            $v->limitacion('Algunas variantes no se pudieron comprobar (p. ej., fuera del ámbito autorizado).');
        }
        return $v->correcto();
    }
}
