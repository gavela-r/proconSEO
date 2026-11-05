<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-07 (enlazado interno) y SEO-08.contradiccion-tecnica.
 */
final class Enlazado implements Comprobacion
{
    /** Textos de enlace que no dicen adónde llevan (en minúsculas y sin puntuación final). */
    private const AMBIGUOS = [
        'aquí', 'aqui', 'pincha aquí', 'pincha aqui', 'haz clic aquí', 'haz click aquí', 'clic aquí', 'click aquí',
        'click aqui', 'clic aqui', 'pulsa aquí', 'leer más', 'leer mas', 'más', 'mas', 'ver más', 'ver mas',
        'más información', 'mas informacion', 'más info', '+ info', '+info', 'saber más', 'seguir leyendo', 'enlace',
        'link', 'este enlace', 'click here', 'here', 'read more', 'more', 'learn more',
    ];

    /** Mínimo de páginas que promete Proconsi en el Kit Digital (especificación 3.1). */
    public const PAGINAS_MINIMAS_KIT = 3;

    public function subcomprobaciones(): array
    {
        return [
            'SEO-07.enlaces-rastreables' => Cobertura::Implementada,
            'SEO-07.enlaces-rotos' => Cobertura::Implementada,
            'SEO-07.textos-ambiguos' => Cobertura::Implementada,
            'SEO-07.paginas-minimas' => Cobertura::Implementada,
            'SEO-08.contradiccion-tecnica' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'SEO-07.enlaces-rastreables' => $this->rastreables($e),
            'SEO-07.enlaces-rotos' => $this->rotos($e),
            'SEO-07.textos-ambiguos' => $this->ambiguos($e),
            'SEO-07.paginas-minimas' => $this->paginasMinimas($e),
            'SEO-08.contradiccion-tecnica' => $this->contradiccion($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function rastreables(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-07.enlaces-rastreables');
        $noRastreables = [];
        foreach ($doc->enlaces as $a) {
            if ($a['tieneHref'] && Url::esquema($a['href']) === 'javascript') {
                $noRastreables[] = '<a href="' . mb_substr($a['href'], 0, 60) . '">' . $a['texto'] . '</a>';
            }
        }
        $xp = new DOMXPath($doc->dom);
        foreach ($xp->query('//a[not(@href)][@onclick]|//*[not(self::a) and not(self::button) and not(self::input)][@onclick][not(ancestor::a)]') ?: [] as $el) {
            $texto = trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
            if ($texto !== '' && mb_strlen($texto) < 80) {
                $noRastreables[] = '<' . $el->nodeName . ' onclick="…">' . $texto . '</' . $el->nodeName . '>';
            }
        }
        if ($doc->enlaces === [] && $noRastreables === []) {
            return $v->noAplicable('La página no tiene enlaces.');
        }
        if ($noRastreables === []) {
            return $v->valor(count($doc->enlaces) . ' enlaces, todos con <a href> real.')->correcto();
        }
        return $v->valor(count($noRastreables) . ' enlace(s) que solo funcionan con JavaScript.')
            ->elemento($noRastreables[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($noRastreables, 0, 20))))
            ->consecuencia('Google solo sigue enlaces <a href="URL">: las páginas a las que llevan pueden quedar sin descubrir.')
            ->correccion('Convertirlos en <a href="https://…"> con la dirección real; el JavaScript puede seguir añadiéndose encima.')
            ->problema(Gravedad::Medio);
    }

    private function rotos(Entorno $e): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'comprobar si los enlaces internos funcionan');
        $internos = $e->enlacesInternos();
        $v = $e->resultado('SEO-07.enlaces-rotos');
        if ($internos === []) {
            return $v->noAplicable('La página no tiene enlaces internos.');
        }
        [$rotos, $bien, $sinComprobar] = $this->consultar($e, $internos, 'SEO-07.enlaces-rotos');

        $detalle = array_merge(
            array_map(static fn (string $r): string => 'Roto: ' . $r, $rotos),
            array_map(static fn (string $r): string => 'Sin comprobar: ' . $r, $sinComprobar),
        );
        $v->valor(count($bien) . ' funcionan, ' . count($rotos) . ' rotos, ' . count($sinComprobar) . ' sin comprobar (de ' . count($internos) . ' enlaces internos).');
        if ($detalle !== []) {
            $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $detalle)));
        }
        if ($sinComprobar !== []) {
            $v->limitacion('Algunos enlaces no se pudieron comprobar (límite de URLs, fuera del ámbito o sin respuesta).');
        }
        if ($rotos !== []) {
            return $v->elemento($rotos[0])
                ->consecuencia('Quien pulsa el enlace llega a una página de error, y el buscador gasta su visita en una URL que no existe.')
                ->correccion('Corregir la dirección de los enlaces rotos o redirigir con 301 las páginas que se movieron.')
                ->problema(Gravedad::Medio);
        }
        if ($bien === []) {
            throw new NoVerificable('No se pudo comprobar ningún enlace interno.');
        }
        return $v->correcto();
    }

    private function ambiguos(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-07.textos-ambiguos');
        $navegables = array_filter($doc->enlaces, static fn (array $a): bool => $a['url'] !== null);
        if ($navegables === []) {
            return $v->noAplicable('La página no tiene enlaces.');
        }
        $ambiguos = [];
        foreach ($navegables as $a) {
            $texto = mb_strtolower(trim((string) preg_replace('/[\s.…:!»›→]+$/u', '', $a['nombre'])));
            if (in_array($texto, self::AMBIGUOS, true)) {
                $ambiguos[] = '«' . $a['nombre'] . '» → ' . $a['url'];
            }
        }
        if ($ambiguos === []) {
            return $v->valor('Ningún enlace usa textos genéricos como «aquí» o «leer más».')->correcto();
        }
        return $v->valor(count($ambiguos) . ' enlace(s) con texto genérico.')
            ->elemento($ambiguos[0])
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($ambiguos, 0, 20))))
            ->consecuencia('«Pincha aquí» no dice adónde lleva: el buscador entiende peor la página enlazada y quien usa lector de pantalla oye una lista de «aquí».')
            ->correccion('Usar como texto del enlace lo que hay en el destino, p. ej. «Ver todos nuestros trabajos de reforma» en lugar de «pincha aquí».')
            ->mejorable();
    }

    private function paginasMinimas(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('SEO-07.paginas-minimas');
        if (!$e->esKitDigital()) {
            return $v->noAplicable('Solo se valora en proyectos Web Kit Digital y Tienda Kit Digital.');
        }
        $e->exigeAlcance(Alcance::UrlContexto, 'contar las páginas internas');
        $internos = $e->enlacesInternos();
        $necesarias = self::PAGINAS_MINIMAS_KIT - 1; // La propia página cuenta como una.
        [, $bien, $sinComprobar] = $this->consultar($e, $internos, 'SEO-07.paginas-minimas', $necesarias);

        $v->valor((count($bien) + 1) . ' página(s) accesibles contando esta; ' . count($internos) . ' enlaces internos distintos.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, "Accesibles:\n" . implode("\n", array_merge([$e->url()], $bien))));
        if (count($bien) >= $necesarias) {
            return $v->correcto();
        }
        if ($sinComprobar !== []) {
            throw new NoVerificable('Solo se han podido comprobar ' . (count($bien) + 1) . ' páginas; otras ' . count($sinComprobar) . ' no se pudieron consultar.');
        }
        return $v->consecuencia('El Kit Digital compromete una web de al menos ' . self::PAGINAS_MINIMAS_KIT . ' páginas, y desde aquí solo se llega a ' . (count($bien) + 1) . '.')
            ->correccion('Crear las páginas que faltan (p. ej., servicios y contacto) y enlazarlas desde el menú.')
            ->limitacion('Se cuentan las páginas enlazadas desde la página analizada.')
            ->problema(Gravedad::Medio);
    }

    /**
     * Consulta enlaces internos y los clasifica. Con $bastan, para en cuanto hay suficientes
     * páginas accesibles.
     *
     * @param list<string> $urls
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} rotos, accesibles, sin comprobar
     */
    private function consultar(Entorno $e, array $urls, string $id, ?int $bastan = null): array
    {
        $rotos = [];
        $bien = [];
        $sinComprobar = [];
        foreach ($urls as $url) {
            if ($bastan !== null && count($bien) >= $bastan) {
                break;
            }
            try {
                $r = $e->obtener($url, 'enlace interno de la página analizada', $id);
            } catch (NoVerificable) {
                $sinComprobar[] = $url . ' (límite de URLs)';
                continue;
            }
            $codigo = $r->estadoHttp;
            if ($codigo !== null && $codigo >= 200 && $codigo < 400 && $r->esCorrecto()) {
                $bien[] = $url;
            } elseif ($codigo !== null && in_array($codigo, [404, 410], true) || ($codigo !== null && $codigo >= 500)) {
                $rotos[] = $url . ' (HTTP ' . $codigo . ')';
            } else {
                $sinComprobar[] = $url . ' (' . Entorno::describirDescarga($r) . ')';
            }
        }
        return [$rotos, $bien, $sinComprobar];
    }

    private function contradiccion(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-08.contradiccion-tecnica');
        $propia = Url::sinFragmento($e->url());
        $canonicos = array_values(array_unique(array_map([Url::class, 'sinFragmento'], $doc->canonicos)));
        $contradicciones = [];

        if (count($canonicos) > 1) {
            $contradicciones[] = ['problema', 'Hay ' . count($canonicos) . ' canonical distintos: ' . implode(', ', $canonicos) . '.'];
        }
        $hreflangPropio = false;
        foreach ($doc->alternativasIdioma as $alt) {
            if ($alt['url'] !== null && Url::sinFragmento($alt['url']) === $propia) {
                $hreflangPropio = true;
            }
        }
        if (count($canonicos) === 1 && $canonicos[0] !== $propia && $hreflangPropio) {
            $contradicciones[] = ['mejorable', 'El canonical apunta a ' . $canonicos[0] . ' pero el hreflang presenta esta URL como la versión de su idioma.'];
        }
        $ogUrl = null;
        foreach ($doc->metas as $m) {
            if ($m['propiedad'] === 'og:url' && $m['contenido'] !== null) {
                $ogUrl = Url::resolver($e->url(), $m['contenido']);
            }
        }
        if ($ogUrl !== null && count($canonicos) === 1 && Url::sinFragmento($ogUrl) !== $canonicos[0]) {
            $contradicciones[] = ['mejorable', 'og:url (' . $ogUrl . ') no coincide con el canonical (' . $canonicos[0] . ').'];
        }

        if ($contradicciones === []) {
            return $v->valor('canonical, hreflang y og:url no se contradicen.')
                ->limitacion('Con una sola página no se detecta contenido duplicado entre URLs (SEO-08.contenido-duplicado).')
                ->correcto();
        }
        $textos = array_column($contradicciones, 1);
        $v->valor(implode(' ', $textos))->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $textos)))
            ->consecuencia('Con señales que se contradicen, Google decide por su cuenta qué URL mostrar.')
            ->correccion('Hacer que canonical, hreflang y og:url apunten a la misma URL preferida.');
        return in_array('problema', array_column($contradicciones, 0), true) ? $v->problema(Gravedad::Medio) : $v->mejorable();
    }
}
