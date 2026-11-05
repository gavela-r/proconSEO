<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use DateTimeImmutable;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Engine\Extraccion\DocumentoHtml;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Robots\AnalizadorRobots;
use ProconSEO\Engine\Extraccion\Robots\ReglasRobots;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Shared\Contract\BorradorEvidencia;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\TipoEvidencia;
use ProconSEO\Shared\Enum\TipoProyecto;
use RuntimeException;

/**
 * Todo lo que una comprobación puede consultar durante un análisis. Calcula cada dato una sola
 * vez (página extraída, robots.txt, CMS) y guarda las evidencias que se van generando.
 *
 * Los métodos exige…() lanzan NoVerificable cuando falta el dato: la comprobación no tiene que
 * preocuparse de los fallos de descarga.
 */
final class Entorno
{
    private ?DocumentoHtml $documento = null;
    private ?string $errorDocumento = null;
    private bool $documentoLeido = false;

    /** @var array{0: string, 1: ?ReglasRobots, 2: RecursoDescargado}|null estado, reglas, recurso */
    private ?array $robots = null;

    /** @var array<string, list<string>>|null */
    private ?array $cms = null;

    /** @var list<BorradorEvidencia> */
    private array $evidencias = [];

    /** URLs extra pedidas (sin contar robots.txt ni la página), para el límite del perfil. */
    private int $urlsExtra = 0;

    /** Extensiones que no son páginas HTML. */
    private const NO_PAGINAS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'zip', 'rar', 'doc', 'docx',
        'xls', 'xlsx', 'ppt', 'pptx', 'mp4', 'mp3', 'webm', 'mov', 'xml', 'txt', 'csv', 'ics', 'exe', 'dmg'];

    public function __construct(
        public readonly ContextoAnalisis $contexto,
        public readonly ProveedorConMemoria $proveedor,
        public readonly CatalogoJson $catalogo,
        private readonly AnalizadorHtml $analizadorHtml = new AnalizadorHtml(),
        private readonly AnalizadorRobots $analizadorRobots = new AnalizadorRobots(),
    ) {
        $proveedor->recordar($contexto->paginaPrincipal);
    }

    public function pagina(): RecursoDescargado
    {
        return $this->contexto->paginaPrincipal;
    }

    /** URL de la página tal como quedó tras las redirecciones. */
    public function url(): string
    {
        return $this->pagina()->urlFinal;
    }

    /**
     * La página se descargó y su respuesta es un documento que se puede valorar (2xx).
     * Un 403 con CAPTCHA o un 500 no son la página: su HTML no se analiza.
     */
    public function paginaDisponible(): bool
    {
        $p = $this->pagina();
        return $p->esCorrecto() && $p->estadoHttp !== null && $p->estadoHttp >= 200 && $p->estadoHttp < 300;
    }

    public function exigePagina(): void
    {
        if (!$this->paginaDisponible()) {
            throw new NoVerificable('No se pudo recuperar la página: ' . self::describirDescarga($this->pagina()) . '.', Causas::PAGINA_NO_RECUPERADA);
        }
    }

    /** Hay una respuesta HTTP (aunque sea un error), con su código y cabeceras. */
    public function exigeRespuesta(): void
    {
        if ($this->pagina()->estadoHttp === null) {
            throw new NoVerificable('No hubo respuesta del servidor: ' . self::describirDescarga($this->pagina()) . '.', Causas::PAGINA_NO_RECUPERADA);
        }
    }

    public function documento(): DocumentoHtml
    {
        $this->exigePagina();
        if (!$this->documentoLeido) {
            $this->documentoLeido = true;
            try {
                $this->documento = $this->analizadorHtml->analizar($this->pagina()->cuerpo, $this->url());
            } catch (ErrorExtraccion $e) {
                $this->errorDocumento = $e->getMessage();
            }
        }
        if ($this->documento === null) {
            throw new NoVerificable('No se pudo leer el HTML de la página: ' . $this->errorDocumento, Causas::HTML_ILEGIBLE);
        }
        return $this->documento;
    }

    /**
     * La página apenas tiene texto en el HTML inicial y carga scripts: lo que «falta» puede
     * aparecer al ejecutar JavaScript. Sin navegador no se puede afirmar una ausencia
     * (especificación 10, «Contenido que aparece tras la carga»).
     */
    public function contenidoPuedeDependerDeJs(): bool
    {
        $doc = $this->documento();
        if (mb_strlen($doc->texto) >= 200) {
            return false;
        }
        $xp = new \DOMXPath($doc->dom);
        // Señales de aplicación JavaScript: contenedor raíz vacío, scripts de módulo o aviso <noscript>.
        $raizVacia = $xp->query('//body//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @data-reactroot or @ng-app or @ng-version][not(normalize-space())]');
        $modulos = $xp->query('//script[@type="module"]');
        $avisos = $xp->query('//noscript[contains(translate(., "JAVSCRIPT", "javscript"), "javascript")]');
        return ($raizVacia !== false && $raizVacia->length > 0)
            || ($modulos !== false && $modulos->length > 0)
            || ($avisos !== false && $avisos->length > 0);
    }

    /** Lanza NoVerificable si una ausencia podría deberse a contenido que añade JavaScript. */
    public function exigeHtmlCompleto(string $queFalta): void
    {
        if ($this->contenidoPuedeDependerDeJs()) {
            throw new NoVerificable("No se encuentra $queFalta en el HTML inicial, pero la página carga casi todo con JavaScript: sin navegador no se puede afirmar que falte.", Causas::CONTENIDO_JAVASCRIPT);
        }
    }

    /** El análisis permite consultar otras URLs (alcance con contexto o sitio completo). */
    public function exigeAlcance(Alcance $minimo, string $paraQue): void
    {
        $orden = [Alcance::UrlIndividual->value => 0, Alcance::UrlContexto->value => 1, Alcance::SitioCompleto->value => 2];
        if ($orden[$this->contexto->alcance->value] < $orden[$minimo->value]) {
            throw new NoVerificable("Para $paraQue hace falta el alcance «{$minimo->etiqueta()}»; el análisis es «{$this->contexto->alcance->etiqueta()}».", Causas::ALCANCE_INSUFICIENTE);
        }
    }

    /**
     * Pide una URL a la Parte 1. Las URLs extra (todo salvo robots.txt) cuentan para el límite
     * del perfil (rastreo.urls_contexto, 20 en v1); al agotarlo, lanza NoVerificable y no pide nada.
     */
    public function obtener(string $url, string $motivo, string $idComprobacion): RecursoDescargado
    {
        if (!$this->proveedor->tiene($url) && !str_ends_with((string) parse_url($url, PHP_URL_PATH), '/robots.txt')) {
            if ($this->urlsExtra >= $this->limiteUrlsExtra()) {
                throw new NoVerificable('Se alcanzó el límite de ' . $this->limiteUrlsExtra() . ' URLs adicionales del perfil de ejecución.', Causas::LIMITE_URLS);
            }
            $this->urlsExtra++;
        }
        return $this->proveedor->obtener($url, $motivo, $idComprobacion);
    }

    public function limiteUrlsExtra(): int
    {
        $clave = $this->contexto->alcance === Alcance::SitioCompleto ? 'paginas_sitio_completo' : 'urls_contexto';
        $valor = $this->contexto->perfil['rastreo'][$clave]['valor'] ?? null;
        return is_int($valor) && $valor > 0 ? $valor : ($clave === 'urls_contexto' ? 20 : 200);
    }

    /** true si el host de la URL es el de la página o uno de los autorizados. */
    public function esInterna(string $url): bool
    {
        $host = Url::host($url);
        if ($host === null) {
            return false;
        }
        return $host === Url::host($this->url()) || in_array($host, array_map('strtolower', $this->contexto->hostsAutorizados), true);
    }

    /**
     * Páginas internas enlazadas desde la página analizada: http(s), del ámbito, sin fragmento,
     * sin repetir, sin la propia página y sin ficheros que no son HTML. En orden de aparición.
     *
     * @return list<string>
     */
    public function enlacesInternos(): array
    {
        $propia = Url::sinFragmento($this->url());
        $urls = [];
        foreach ($this->documento()->enlaces as $a) {
            if ($a['url'] === null) {
                continue;
            }
            $url = Url::sinFragmento($a['url']);
            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($url === $propia || !$this->esInterna($url) || in_array($extension, self::NO_PAGINAS, true)) {
                continue;
            }
            $urls[$url] = true;
        }
        return array_keys($urls);
    }

    public const ROBOTS_LEIDO = 'leido';
    public const ROBOTS_SIN_RESTRICCIONES = 'sin_restricciones';
    public const ROBOTS_TEMPORAL = 'restriccion_temporal';
    public const ROBOTS_INACCESIBLE = 'inaccesible';

    /**
     * robots.txt del origen de la página, interpretado según la RFC 9309:
     * 200 → reglas; 4xx (salvo 429) → sin restricciones; 429 y 5xx → restricción temporal;
     * sin respuesta → inaccesible.
     *
     * @return array{estado: string, reglas: ?ReglasRobots, recurso: RecursoDescargado}
     */
    public function robots(): array
    {
        if ($this->robots === null) {
            $origen = self::origen($this->url()) ?? self::origen($this->pagina()->urlSolicitada);
            if ($origen === null) {
                throw new RuntimeException('La URL analizada no es absoluta.');
            }
            $r = $this->obtener($origen . '/robots.txt', 'robots.txt del origen', 'IA-01.robots-recuperado');
            $codigo = $r->estadoHttp;
            if ($r->esCorrecto() && $codigo !== null && $codigo >= 200 && $codigo < 300) {
                $this->robots = [self::ROBOTS_LEIDO, $this->analizadorRobots->analizar($r->cuerpo), $r];
            } elseif ($codigo === 429 || $r->resultado === ResultadoDescarga::Limitado429 || ($codigo !== null && $codigo >= 500)) {
                $this->robots = [self::ROBOTS_TEMPORAL, null, $r];
            } elseif ($codigo !== null && $codigo >= 400) {
                $this->robots = [self::ROBOTS_SIN_RESTRICCIONES, null, $r];
            } else {
                $this->robots = [self::ROBOTS_INACCESIBLE, null, $r];
            }
        }
        return ['estado' => $this->robots[0], 'reglas' => $this->robots[1], 'recurso' => $this->robots[2]];
    }

    /** robots.txt si ya se pidió y respondió 2xx (no lo descarga si no se pidió). */
    public function robotsLeido(): ?RecursoDescargado
    {
        return ($this->robots[0] ?? null) === self::ROBOTS_LEIDO ? $this->robots[2] : null;
    }

    /**
     * Si el robot puede rastrear la URL analizada. Lanza NoVerificable si robots.txt no se pudo
     * consultar o dio un error temporal.
     *
     * @return array{permitido: bool, regla: ?string, linea: ?int}
     */
    public function permisoRobots(string $agente): array
    {
        $robots = $this->robots();
        return match ($robots['estado']) {
            self::ROBOTS_LEIDO => $robots['reglas']?->evaluar($agente, $this->url()) ?? throw new RuntimeException('Sin reglas'),
            self::ROBOTS_SIN_RESTRICCIONES => ['permitido' => true, 'regla' => null, 'linea' => null],
            self::ROBOTS_TEMPORAL => throw new NoVerificable('robots.txt dio un error temporal (' . self::describirDescarga($robots['recurso']) . '): mientras dure, los buscadores pueden dejar de rastrear el sitio.', Causas::ROBOTS_ERROR_TEMPORAL),
            default => throw new NoVerificable('No se pudo consultar robots.txt: ' . self::describirDescarga($robots['recurso']) . '.', Causas::ROBOTS_NO_CONSULTABLE),
        };
    }

    /** @return array<string, list<string>> CMS detectados y sus señales; vacío si la página no está disponible */
    public function cms(): array
    {
        if ($this->cms === null) {
            try {
                $this->cms = Cms::detectar($this->documento());
            } catch (NoVerificable) {
                $this->cms = [];
            }
        }
        return $this->cms;
    }

    public function esKitDigital(): bool
    {
        return in_array($this->contexto->tipoProyecto, [TipoProyecto::WebKitDigital, TipoProyecto::TiendaKitDigital], true);
    }

    public function pideNoIndexar(): bool
    {
        return Objetivo::pideNoIndexar($this->contexto->objetivo);
    }

    /** Guarda una evidencia mínima y devuelve su código (E-001…). */
    public function evidencia(TipoEvidencia $tipo, string $contenido, ?string $url = null, ?DateTimeImmutable $capturadoEn = null): string
    {
        $codigo = sprintf('E-%03d', count($this->evidencias) + 1);
        $this->evidencias[] = new BorradorEvidencia(
            codigo: $codigo,
            tipo: $tipo,
            contenido: $contenido,
            urlRecurso: $url ?? $this->url(),
            capturadoEn: $capturadoEn ?? $this->pagina()->descargadoEn ?? new DateTimeImmutable(),
        );
        return $codigo;
    }

    /** Evidencia con el código HTTP, la URL y las cabeceras relevantes de una respuesta. */
    public function evidenciaHttp(RecursoDescargado $r, array $cabeceras = []): string
    {
        $lineas = [($r->estadoHttp === null ? 'Sin respuesta' : 'HTTP ' . $r->estadoHttp) . ' — ' . $r->urlFinal];
        foreach ($r->redirecciones as $salto) {
            $lineas[] = $salto['estado'] . ' ' . $salto['url'] . ' → ' . ($salto['destino'] ?? '(sin destino)');
        }
        foreach ($cabeceras as $nombre) {
            $valor = $r->cabecera($nombre);
            if ($valor !== null) {
                $lineas[] = $nombre . ': ' . $valor;
            }
        }
        if ($r->motivo !== null) {
            $lineas[] = 'Motivo: ' . $r->motivo;
        }
        return $this->evidencia(TipoEvidencia::IntercambioHttp, implode("\n", $lineas), $r->urlSolicitada);
    }

    /** @return list<BorradorEvidencia> */
    public function evidencias(): array
    {
        return $this->evidencias;
    }

    public function resultado(string $id): Valoracion
    {
        $ficha = $this->catalogo->obtener($id) ?? throw new RuntimeException("$id no está en el catálogo.");
        return new Valoracion($ficha, $this->catalogo->version(), $this);
    }

    public static function origen(string $url): ?string
    {
        $esquema = Url::esquema($url);
        $partes = parse_url($url);
        if (!in_array($esquema, ['http', 'https'], true) || !isset($partes['host'])) {
            return null;
        }
        return $esquema . '://' . strtolower($partes['host']) . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }

    public static function describirDescarga(RecursoDescargado $r): string
    {
        $texto = $r->resultado->etiqueta();
        if ($r->estadoHttp !== null) {
            $texto .= ' (HTTP ' . $r->estadoHttp . ')';
        }
        if ($r->motivo !== null && $r->motivo !== '') {
            $texto .= ', ' . $r->motivo;
        }
        return $texto;
    }
}
