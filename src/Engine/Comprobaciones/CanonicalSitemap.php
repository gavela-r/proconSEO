<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Estructurados;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Sitemap\AnalizadorSitemap;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-08: canonical declarado, presencia en el sitemap y coherencia de los datos del negocio
 * entre páginas. El enlazado interno hacia la URL necesita el sitio completo y queda para
 * cuando la Parte 1 entregue el rastreo.
 */
final class CanonicalSitemap implements Comprobacion
{
    /** Sitemaps (contando índices) que se leen como máximo para buscar la URL. */
    private const MAXIMO_SITEMAPS = 5;

    /** Páginas que suelen repetir los datos de contacto. */
    private const PAGINAS_CONTACTO = '/contact|donde-estamos|como-llegar|ubicacion|localizacion|quienes-somos/i';

    public function subcomprobaciones(): array
    {
        return [
            'IA-08.canonical-declarado' => Cobertura::Implementada,
            'IA-08.presencia-sitemap' => Cobertura::Implementada,
            'IA-08.datos-negocio-coherentes' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'IA-08.canonical-declarado' => $this->canonical($entorno),
            'IA-08.presencia-sitemap' => $this->sitemap($entorno),
            'IA-08.datos-negocio-coherentes' => $this->datosNegocio($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function canonical(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('IA-08.canonical-declarado');
        $canonicos = array_values(array_unique(array_map([Url::class, 'sinFragmento'], $doc->canonicos)));
        if ($canonicos === []) {
            return $v->valor('No hay <link rel="canonical">.')
                ->consecuencia('Si la página se puede abrir con varias direcciones (con parámetros, con y sin barra…), el buscador tiene que adivinar cuál es la buena.')
                ->correccion('Añadir en el <head> <link rel="canonical" href="' . Url::sinFragmento($e->url()) . '">.')
                ->correccionCms([
                    'wordpress' => 'Yoast SEO y Rank Math lo añaden automáticamente al activarlos.',
                    'prestashop' => 'Activar en el módulo SEO del tema la etiqueta canonical, o un módulo de URL canónicas.',
                ])
                ->mejorable();
        }
        $v->valor(implode(', ', $canonicos))->elemento('<link rel="canonical" href="' . $canonicos[0] . '">');
        if (count($canonicos) > 1) {
            return $v->consecuencia('Con varios canonical distintos, Google los ignora todos.')
                ->correccion('Dejar un único canonical.')
                ->problema(Gravedad::Medio);
        }
        if (!$e->esInterna($canonicos[0])) {
            return $v->consecuencia('El canonical apunta a otro dominio: se pide a Google que muestre esa otra web en lugar de esta.')
                ->correccion('Si no es intencionado (contenido sindicado, cambio de dominio), apuntar el canonical a esta misma web.')
                ->revisionHumana('Un canonical a otro dominio puede ser deliberado.')
                ->decisionRevisar();
        }
        return $v->correcto();
    }

    private function sitemap(Entorno $e): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'buscar la URL en el sitemap');
        $e->exigePagina();
        $v = $e->resultado('IA-08.presencia-sitemap');
        $buscadas = array_values(array_unique(array_filter([Url::sinFragmento($e->url()), $e->documento()->canonicos[0] ?? null])));

        $robots = $e->robots();
        $origen = Entorno::origen($e->url()) ?? throw new NoVerificable('La URL analizada no es absoluta.');
        $declarados = $robots['reglas']?->sitemaps ?? [];
        $pendientes = $declarados !== [] ? $declarados : [$origen . '/sitemap.xml'];
        $leidos = [];
        $avisos = [];
        $analizador = new AnalizadorSitemap();

        while ($pendientes !== [] && count($leidos) < self::MAXIMO_SITEMAPS) {
            $url = array_shift($pendientes);
            if (isset($leidos[$url])) {
                continue;
            }
            $leidos[$url] = true;
            try {
                $r = $e->obtener($url, 'sitemap del sitio', 'IA-08.presencia-sitemap');
            } catch (NoVerificable $nv) {
                $avisos[] = $url . ': ' . $nv->getMessage();
                break;
            }
            if (!$r->esCorrecto() || $r->estadoHttp === null || $r->estadoHttp >= 300) {
                $avisos[] = $url . ': ' . Entorno::describirDescarga($r);
                continue;
            }
            try {
                $contenido = $analizador->analizar($r->cuerpo);
            } catch (ErrorExtraccion $x) {
                $avisos[] = $url . ': ' . $x->getMessage();
                continue;
            }
            if ($contenido->esIndice()) {
                foreach ($contenido->urls() as $hijo) {
                    if ($e->esInterna($hijo)) {
                        $pendientes[] = $hijo;
                    }
                }
                continue;
            }
            foreach ($buscadas as $b) {
                if ($contenido->contiene($b) || $contenido->contiene(rtrim($b, '/')) || $contenido->contiene($b . '/')) {
                    return $v->valor('La URL aparece en ' . $url . '.')
                        ->evidencia($e->evidenciaHttp($r))
                        ->correcto();
                }
            }
        }

        $v->evidencia($e->evidencia(TipoEvidencia::Texto, 'Sitemaps consultados: ' . implode(', ', array_keys($leidos)) . ($avisos !== [] ? "\n" . implode("\n", $avisos) : '')));
        $legibles = count($leidos) - count($avisos);
        if ($legibles <= 0) {
            if ($declarados === [] && $avisos !== [] && str_contains(implode(' ', $avisos), '404')) {
                return $v->valor('No hay sitemap: robots.txt no declara ninguno y /sitemap.xml no existe.')
                    ->consecuencia('Sin sitemap, los buscadores solo descubren las páginas siguiendo enlaces.')
                    ->correccion('Publicar un sitemap.xml con las páginas importantes y declararlo en robots.txt («Sitemap: https://…/sitemap.xml»).')
                    ->correccionCms([
                        'wordpress' => 'WordPress genera /wp-sitemap.xml; Yoast y Rank Math, /sitemap_index.xml. Declararlo en robots.txt o en Google Search Console.',
                        'prestashop' => 'Instalar el módulo oficial «Google sitemap» y declarar el fichero en robots.txt.',
                    ])
                    ->mejorable();
            }
            throw new NoVerificable('No se pudo leer ningún sitemap: ' . implode('; ', $avisos));
        }
        if ($pendientes !== []) {
            $v->limitacion('Solo se han leído ' . self::MAXIMO_SITEMAPS . ' sitemaps.');
            throw new NoVerificable('La URL no está en los ' . self::MAXIMO_SITEMAPS . ' primeros sitemaps leídos; quedan otros por revisar.');
        }
        return $v->valor('La URL no aparece en ' . implode(', ', array_keys($leidos)) . '.')
            ->consecuencia('Los buscadores priorizan las URLs del sitemap: si una página importante no está, se descubre y actualiza más tarde.')
            ->correccion('Añadir la URL al sitemap (o comprobar que el sitemap se genera con todas las páginas públicas).')
            ->mejorable();
    }

    private function datosNegocio(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('IA-08.datos-negocio-coherentes');
        $doc = $e->documento();
        $tieneNegocio = array_filter(Estructurados::nodosJsonLd($doc), static fn (array $n): bool => Estructurados::esDeTipo($n, ...Estructurados::NEGOCIO_LOCAL)) !== [];
        $sectorLocal = in_array($e->contexto->sector, [Sector::Hidrocarburos, Sector::Salud, Sector::Alimentacion], true);
        if (!$tieneNegocio && !$sectorLocal) {
            return $v->noAplicable('No es un negocio con atención local (ni por sector ni por marcado).');
        }
        $e->exigeAlcance(Alcance::UrlContexto, 'comparar los datos del negocio entre páginas');

        $propios = Estructurados::telefonos($doc);
        if ($propios === []) {
            throw new NoVerificable('La página no muestra ningún teléfono con el que comparar.');
        }
        $candidatas = array_values(array_filter($e->enlacesInternos(), static fn (string $u): bool => preg_match(self::PAGINAS_CONTACTO, $u) === 1));
        if ($candidatas === []) {
            throw new NoVerificable('No se ha encontrado una página de contacto enlazada con la que comparar.');
        }

        $analizador = new AnalizadorHtml();
        $comparadas = [];
        $distintas = [];
        foreach (array_slice($candidatas, 0, 2) as $url) {
            $r = $e->obtener($url, 'página de contacto para comparar los datos del negocio', 'IA-08.datos-negocio-coherentes');
            if (!$r->esCorrecto() || $r->estadoHttp === null || $r->estadoHttp >= 300) {
                continue;
            }
            try {
                $otros = Estructurados::telefonos($analizador->analizar($r->cuerpo, $r->urlFinal));
            } catch (ErrorExtraccion) {
                continue;
            }
            if ($otros === []) {
                continue;
            }
            $comparadas[] = $url;
            if (array_intersect($propios, $otros) === []) {
                $distintas[] = $url . ': ' . implode(', ', $otros);
            }
        }
        if ($comparadas === []) {
            throw new NoVerificable('No se pudo leer el teléfono de ninguna página de contacto.');
        }

        $v->limitacion('Se compara el teléfono; la dirección y el horario quedan para una versión posterior.');
        if ($distintas === []) {
            return $v->valor('El teléfono (' . implode(', ', $propios) . ') coincide en ' . count($comparadas) . ' página(s) más.')->correcto();
        }
        return $v->valor('Teléfonos distintos: en esta página ' . implode(', ', $propios) . '; ' . implode('; ', $distintas) . '.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Esta página: ' . implode(', ', $propios) . "\n" . implode("\n", $distintas)))
            ->consecuencia('Con datos de contacto distintos en cada página, buscadores y asistentes con IA pueden dar al cliente el teléfono equivocado.')
            ->correccion('Unificar el teléfono en todas las páginas (lo ideal: que salga de un único sitio, como el pie de página).')
            ->problema(Gravedad::Medio);
    }
}
