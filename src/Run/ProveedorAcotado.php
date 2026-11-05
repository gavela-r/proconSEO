<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Engine\Extraccion\Robots\ReglasRobots;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Lo que recibe el motor en lugar del cliente HTTP:
 * - recuerda lo ya descargado, para no pedir robots.txt o el sitemap una vez por página;
 * - pone un tope de URLs nuevas por página analizada (con contexto: 20 además de la página y
 *   robots.txt). Pasado el tope no se descarga nada: se devuelve «fuera de ámbito» con el
 *   motivo, y la comprobación que lo pidió queda no_verificable.
 *
 * - no pide nada que el robots.txt del sitio prohíba a nuestro user-agent: se devuelve
 *   «fuera de ámbito» con el motivo, sin conectarse.
 *
 * En el sitio completo las páginas HTML también se recuerdan: el menú de cada página enlaza a
 * las mismas, y sin memoria se volverían a pedir en todas. Para no llenar la memoria, se guardan
 * como mucho MAX_BYTES_HTML; pasado eso se olvidan las más antiguas. robots.txt, sitemaps y
 * demás se conservan siempre.
 */
final class ProveedorAcotado implements ProveedorRecursos
{
    /** @var array<string, RecursoDescargado> Se conservan todo el análisis */
    private array $permanentes = [];
    /** Lo más que ocupan las páginas HTML recordadas (64 MB). */
    public const MAX_BYTES_HTML = 64 * 1024 * 1024;

    /** @var array<string, RecursoDescargado> Páginas HTML recordadas, de la más antigua a la más reciente */
    private array $html = [];
    private int $bytesHtml = 0;
    private int $nuevas = 0;
    private int $totalNuevas = 0;
    /** @var array<string, array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}> */
    private array $bloqueadas = [];
    private ?ReglasRobots $robots = null;
    private string $origenRobots = '';
    private string $agente = '';

    public function __construct(
        private readonly ProveedorRecursos $proveedor,
        private readonly ?int $maxNuevasPorPagina = null,
        private readonly string $alcance = '',
    ) {
        if ($proveedor instanceof ClienteHttpSeguro) {
            // Las redirecciones que acaban en algo ya descargado no lo vuelven a pedir.
            $proveedor->usarMemoria($this->enMemoria(...));
        }
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        $guardado = $this->enMemoria($url);
        if ($guardado !== null) {
            return $guardado;
        }
        if ($this->prohibidaPorRobots($url)) {
            return $this->bloquear($url, $motivo, $idComprobacion, "El robots.txt del sitio no permite a {$this->agente} consultar esta URL.");
        }
        if ($this->maxNuevasPorPagina !== null && $this->nuevas >= $this->maxNuevasPorPagina) {
            return $this->bloquear($url, $motivo, $idComprobacion, "Se ha llegado al máximo de {$this->maxNuevasPorPagina} URLs extra por página del alcance «{$this->alcance}».");
        }
        $this->nuevas++;
        $this->totalNuevas++;

        $recurso = $this->proveedor->obtener($url, $motivo, $idComprobacion);
        $this->guardar($url, $recurso);

        return $recurso;
    }

    private function guardar(string $url, RecursoDescargado $recurso): void
    {
        if (self::esHtml($recurso)) {
            $this->recordarHtml($url, $recurso);
        } else {
            $this->permanentes[$url] = $recurso;
        }

        // Si llegó tras redirigir, la URL final ya está descargada: se recuerda también como si
        // se hubiera pedido directamente (sin las redirecciones, que no son suyas).
        $final = $recurso->urlFinal;
        if ($final !== $url && $recurso->redirecciones !== [] && $recurso->esCorrecto() && $this->enMemoria($final) === null) {
            $this->guardar($final, new RecursoDescargado($final, $final, $recurso->resultado, $recurso->estadoHttp, [],
                $recurso->cabeceras, $recurso->tipoContenido, $recurso->cuerpo, $recurso->truncado, $recurso->bytes,
                $recurso->tlsVerificado, $recurso->motivo, $recurso->descargadoEn));
        }
    }

    /**
     * Aplica el robots.txt de un origen (esquema, host y puerto, como dice la RFC 9309).
     * Las URLs de otros orígenes no se ven afectadas: tienen su propio robots.txt.
     */
    public function respetarRobots(ReglasRobots $robots, string $origen, string $agente): void
    {
        $this->robots = $robots;
        $this->origenRobots = strtolower($origen);
        $this->agente = $agente;
    }

    /** Registra algo ya descargado (robots.txt, la página inicial) sin contarlo en el tope. */
    public function recordar(RecursoDescargado $recurso): void
    {
        $this->permanentes[$recurso->urlSolicitada] ??= $recurso;
    }

    /** Empieza otra página del sitio: el tope de URLs extra vuelve a cero. */
    public function nuevaPagina(RecursoDescargado $pagina): void
    {
        $this->nuevas = 0;
        $this->recordarHtml($pagina->urlSolicitada, $pagina);
    }

    /**
     * Descarga a la vez lo que las comprobaciones van a pedir enseguida (los enlaces internos y
     * las variantes de la página), para que luego lo encuentren en memoria. Solo es una forma de
     * ir más rápido: pasa por el mismo cliente seguro, no pide lo que robots.txt prohíbe ni lo ya
     * descargado, y no cuenta en el tope porque son URLs que el motor iba a pedir igualmente.
     *
     * @param list<array{0: string, 1: string, 2: ?string}> $peticiones URL, motivo y comprobación
     */
    public function adelantar(array $peticiones): void
    {
        if (!$this->proveedor instanceof ClienteHttpSeguro) {
            return;
        }
        $faltan = [];
        foreach ($peticiones as $p) {
            if (!isset($faltan[$p[0]]) && $this->enMemoria($p[0]) === null && !$this->prohibidaPorRobots($p[0])) {
                $faltan[$p[0]] = $p;
            }
        }
        if ($faltan === []) {
            return;
        }
        $faltan = array_values($faltan);
        foreach ($this->proveedor->obtenerVarias($faltan) as $i => $recurso) {
            $this->guardar($faltan[$i][0], $recurso);
        }
    }

    /** Lo ya descargado para esa URL, sin pedir nada ni contar en el tope. */
    public function enMemoria(string $url): ?RecursoDescargado
    {
        return $this->permanentes[$url] ?? $this->html[$url] ?? null;
    }

    private function recordarHtml(string $url, RecursoDescargado $recurso): void
    {
        if (isset($this->html[$url])) {
            $this->bytesHtml -= strlen($this->html[$url]->cuerpo);
            unset($this->html[$url]);
        }
        $this->html[$url] = $recurso;
        $this->bytesHtml += strlen($recurso->cuerpo);
        while ($this->bytesHtml > self::MAX_BYTES_HTML && count($this->html) > 1) {
            $antigua = array_key_first($this->html);
            $this->bytesHtml -= strlen($this->html[$antigua]->cuerpo);
            unset($this->html[$antigua]);
        }
    }

    /**
     * Lo que se pidió y no se consultó (robots.txt o tope), con el mismo formato que el registro
     * del cliente HTTP, para guardarlo en la lista de recursos del análisis (Anexo A.2).
     *
     * @return list<array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}>
     */
    public function bloqueadas(): array
    {
        return array_values($this->bloqueadas);
    }

    private function bloquear(string $url, string $motivo, ?string $idComprobacion, string $porQue): RecursoDescargado
    {
        $recurso = new RecursoDescargado($url, $url, ResultadoDescarga::FueraDeAmbito, motivo: $porQue);
        $this->bloqueadas[$url] ??= ['recurso' => $recurso, 'motivo' => $motivo, 'comprobacion' => $idComprobacion];

        return $recurso;
    }

    /** URLs extra pedidas en todo el análisis. */
    public function urlsExtra(): int
    {
        return $this->totalNuevas;
    }

    private function prohibidaPorRobots(string $url): bool
    {
        if ($this->robots === null || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '/robots.txt')) {
            return false;
        }
        $origen = strtolower((string) preg_replace('#^([a-z]+://[^/?\#]+).*$#i', '$1', $url));

        return $origen === $this->origenRobots && !$this->robots->permite($this->agente, $url);
    }

    private static function esHtml(RecursoDescargado $r): bool
    {
        return str_contains(strtolower((string) $r->tipoContenido), 'html');
    }
}
