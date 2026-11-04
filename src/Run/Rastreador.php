<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use Generator;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Robots\ReglasRobots;
use ProconSEO\Engine\Extraccion\Sitemap\AnalizadorSitemap;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Contract\TokenCancelacion;

/**
 * Recorre el «sitio completo» (F3, Anexo A.1): sigue los enlaces internos y las URLs de los
 * sitemaps, sin salir de los hosts autorizados, respetando robots.txt para nuestro user-agent,
 * sin repetir URLs y dentro de los límites de páginas, profundidad y variantes.
 *
 * Devuelve las páginas HTML de una en una (generador) para que se analicen y se liberen sin
 * tener todo el sitio en memoria. No hace red por su cuenta: todo pasa por el proveedor.
 * Lo que dicen las páginas solo sirve para encontrar URLs, nunca cambia qué se hace (SEG-07).
 */
final class Rastreador
{
    /** Extensiones que nunca son una página HTML: no se piden. */
    private const NO_PAGINAS = '/\.(?:pdf|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|mp[34]|m4a|webm|ogg|wav|avi|mov|zip|rar|7z|gz|tar|exe|msi|dmg|apk|docx?|xlsx?|pptx?|odt|ods|csv|xml|json|txt|css|js|woff2?|ttf|eot)$/i';

    /** @var array<string, true> */
    private array $vistas = [];
    /** @var array<string, int> ruta sin parámetros => variantes vistas */
    private array $variantes = [];
    /** @var list<array{0: string, 1: int}> URL y profundidad pendientes */
    private array $cola = [];

    private int $analizadas = 0;
    private int $descartadasRobots = 0;
    private int $fallidas = 0;
    private int $sitemapsLeidos = 0;
    private bool $limiteAlcanzado = false;
    private bool $tiempoAgotado = false;

    /**
     * @param ProveedorRecursos $proveedor Para las páginas (sin memoria: se liberan al analizarlas)
     * @param ProveedorRecursos $auxiliar  Para los sitemaps (con memoria, compartida con el motor)
     * @param list<string>      $hosts     Hosts autorizados (exactos)
     */
    public function __construct(
        private readonly ProveedorRecursos $proveedor,
        private readonly ProveedorRecursos $auxiliar,
        private readonly array $hosts,
        private readonly LimitesRastreo $limites,
        private readonly ?ReglasRobots $robots,
        private readonly string $agente,
        private readonly int $maxBytesHtml,
        private readonly int $maxBytesSitemap,
    ) {
    }

    /**
     * Páginas del sitio, sin la inicial (que ya se analiza aparte).
     *
     * @param list<string> $sitemaps Sitemaps declarados en robots.txt
     * @return Generator<int, RecursoDescargado>
     */
    public function paginas(RecursoDescargado $inicial, array $sitemaps, TokenCancelacion $cancelacion, ?float $limiteTiempo = null): Generator
    {
        $this->marcarVista($inicial->urlSolicitada);
        $this->marcarVista($inicial->urlFinal);
        if ($inicial->esCorrecto()) {
            $this->encolarEnlaces($inicial, 1);
        }
        $this->leerSitemaps($sitemaps === [] ? [$this->origen($inicial->urlFinal) . '/sitemap.xml'] : $sitemaps);

        while ($this->cola !== []) {
            if ($cancelacion->estaCancelado()) {
                return;
            }
            if ($limiteTiempo !== null && microtime(true) >= $limiteTiempo) {
                $this->tiempoAgotado = true;

                return;
            }
            if ($this->analizadas >= $this->limites->maxPaginas) {
                $this->limiteAlcanzado = true;

                return;
            }

            [$url, $profundidad] = array_shift($this->cola);
            $pagina = $this->proveedor->obtener($url, "Página del sitio (rastreo, a {$profundidad} clics)");
            $this->marcarVista($pagina->urlFinal);

            if (!$pagina->esCorrecto() || !$this->esHtml($pagina)) {
                $this->fallidas++;
                continue;
            }

            $this->analizadas++;
            if ($profundidad < $this->limites->maxProfundidad) {
                $this->encolarEnlaces($pagina, $profundidad + 1);
            }

            yield $pagina;
        }
    }

    /** @return array{analizadas: int, descartadas_robots: int, fallidas: int, sitemaps: int, pendientes: int, limite_paginas: bool, tiempo_agotado: bool} */
    public function resumen(): array
    {
        return [
            'analizadas' => $this->analizadas,
            'descartadas_robots' => $this->descartadasRobots,
            'fallidas' => $this->fallidas,
            'sitemaps' => $this->sitemapsLeidos,
            'pendientes' => count($this->cola),
            'limite_paginas' => $this->limiteAlcanzado,
            'tiempo_agotado' => $this->tiempoAgotado,
        ];
    }

    private function encolarEnlaces(RecursoDescargado $pagina, int $profundidad): void
    {
        try {
            $documento = (new AnalizadorHtml($this->maxBytesHtml))->analizar($pagina->cuerpo, $pagina->urlFinal);
        } catch (ErrorExtraccion) {
            return;
        }
        foreach ($documento->enlaces as $enlace) {
            if ($enlace['url'] !== null && !$enlace['noNavegable']) {
                $this->encolar($enlace['url'], $profundidad);
            }
        }
    }

    /** @param list<string> $sitemaps */
    private function leerSitemaps(array $sitemaps): void
    {
        $pendientes = $sitemaps;
        while ($pendientes !== [] && $this->sitemapsLeidos < LimitesRastreo::MAX_SITEMAPS) {
            $url = array_shift($pendientes);
            if (!$this->enAmbito($url)) {
                continue;
            }
            $this->sitemapsLeidos++;
            $recurso = $this->auxiliar->obtener($url, 'Sitemap (para encontrar las páginas del sitio)');
            if (!$recurso->esCorrecto()) {
                continue;
            }
            try {
                $contenido = (new AnalizadorSitemap($this->maxBytesSitemap))->analizar($recurso->cuerpo);
            } catch (ErrorExtraccion) {
                continue;
            }
            foreach ($contenido->urls() as $entrada) {
                if ($contenido->esIndice()) {
                    $pendientes[] = $entrada;
                } else {
                    $this->encolar($entrada, 1);
                }
            }
        }
    }

    private function encolar(string $url, int $profundidad): void
    {
        $url = $this->normalizar($url);
        if ($url === null || isset($this->vistas[$url]) || !$this->enAmbito($url) || preg_match(self::NO_PAGINAS, (string) parse_url($url, PHP_URL_PATH))) {
            return;
        }

        $ruta = strtok($url, '?');
        if ($ruta !== $url && ($this->variantes[$ruta] ?? 0) >= LimitesRastreo::MAX_VARIANTES) {
            return;
        }

        if ($this->robots !== null && !$this->robots->permite($this->agente, $url)) {
            $this->vistas[$url] = true;
            $this->descartadasRobots++;

            return;
        }

        $this->marcarVista($url);
        $this->variantes[$ruta] = ($this->variantes[$ruta] ?? 0) + 1;
        $this->cola[] = [$url, $profundidad];
    }

    private function marcarVista(string $url): void
    {
        $normalizada = $this->normalizar($url);
        if ($normalizada !== null) {
            $this->vistas[$normalizada] = true;
        }
    }

    /** Sin fragmento, esquema y host en minúsculas y sin el puerto por defecto. */
    private function normalizar(string $url): ?string
    {
        $url = Url::sinFragmento(trim($url));
        if (!preg_match('#^(https?)://([^/?:]+)(?::(\d+))?([/?].*)?$#i', $url, $m)) {
            return null;
        }
        $esquema = strtolower($m[1]);
        $puerto = ($m[3] ?? '') === '' || ($esquema === 'http' && $m[3] === '80') || ($esquema === 'https' && $m[3] === '443') ? '' : ':' . $m[3];
        $resto = ($m[4] ?? '') === '' ? '/' : $m[4];

        return $esquema . '://' . strtolower($m[2]) . $puerto . ($resto[0] === '?' ? '/' . $resto : $resto);
    }

    private function enAmbito(string $url): bool
    {
        $host = Url::host($url);

        return $host !== null && in_array(strtolower($host), $this->hosts, true);
    }

    private function esHtml(RecursoDescargado $pagina): bool
    {
        $tipo = strtolower((string) $pagina->tipoContenido);

        return $tipo === '' || str_contains($tipo, 'text/html') || str_contains($tipo, 'application/xhtml');
    }

    private function origen(string $url): string
    {
        return (string) preg_replace('#^([a-z]+://[^/]+).*$#i', '$1', $url);
    }
}
