<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use RuntimeException;
use Throwable;

/**
 * Pinta una página en un navegador aislado y mide lo que no se puede ver en el HTML:
 * contraste (ACC-05), foco con teclado (ACC-06), reflow a 320 px (ACC-04) y contenido que
 * aparece tras ejecutar JavaScript (SEO-09).
 *
 * Seguridad (SEG-02, SEG-04, SEG-06, SEG-08):
 * - el navegador no tiene red propia (ProcesoNavegador); cada petición suya se intercepta,
 *   pasa por FiltroPeticiones y, si se permite, la descarga ClienteHttpSeguro, que valida la
 *   URL y la IP real de cada subrecurso igual que en el resto del análisis;
 * - perfil y contexto nuevos para cada página, que se borran al terminar;
 * - nada de envíos, descargas, ventanas nuevas ni diálogos (se cierran sin aceptar);
 * - límites de peticiones, bytes y tiempo por página; al llegar a uno se para y se dice.
 */
final class NavegadorAislado
{
    private const ANCHO = 1366;
    private const ALTO = 768;
    private const ALTO_MAXIMO_MEDICION = 8000;
    private const MAX_PASOS_FOCO = 120;

    /** @var array<string, RecursoDescargado> Lo ya descargado para esta página, por URL */
    private array $cache = [];

    /** @var list<array{url: string, tipo: string, permitida: bool, motivo: string}> */
    private array $peticiones = [];

    /** @var list<string> */
    private array $limitaciones = [];

    /** @var array<string, true> Documentos que se esperan en el marco principal (la URL y sus redirecciones) */
    private array $documentosEsperados = [];

    private int $bytes = 0;
    private float $limite = 0.0;
    private ?string $marcoPrincipal = null;
    private ?RecursoDescargado $documento = null;

    public function __construct(
        private readonly string $ejecutable,
        private readonly ClienteHttpSeguro $clientePagina,
        private readonly ClienteHttpSeguro $clienteSubrecursos,
        private readonly FiltroPeticiones $filtro,
        private readonly int $maxPeticiones = 150,
        private readonly int $maxBytes = 20_971_520,
        private readonly int $maxBytesDom = 5_242_880,
        private readonly float $segundos = 45.0,
    ) {
    }

    public function analizar(string $url): PaginaRenderizada
    {
        $inicio = microtime(true);
        $this->limite = $inicio + $this->segundos;
        $this->cache = $this->peticiones = $this->limitaciones = [];
        $this->documentosEsperados = [];
        $this->bytes = 0;
        $this->documento = null;

        $error = null;
        $medidas = [];
        $proceso = null;
        try {
            $proceso = ProcesoNavegador::arrancar($this->ejecutable);
            $medidas = $this->medir($proceso->conexion(), $url);
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Fallo inesperado del navegador.';
        } finally {
            $proceso?->cerrar();
        }
        // Lo rellena atender() desde el manejador de eventos.
        $documento = $this->documentoServido();
        if ($error === null && $documento === null) {
            $error = 'La página no llegó a cargarse en el navegador.';
        }

        return new PaginaRenderizada(
            url: $url,
            error: $error,
            htmlInicial: $documento?->cuerpo,
            dom: $medidas['dom'] ?? null,
            palabrasVisibles: $medidas['palabras'] ?? null,
            contraste: $medidas['contraste'] ?? null,
            foco: $medidas['foco'] ?? null,
            reflow: $medidas['reflow'] ?? null,
            peticiones: $this->peticiones,
            limitaciones: $this->limitaciones,
            segundos: round(microtime(true) - $inicio, 2),
        );
    }

    /** @return array<string, mixed> */
    private function medir(ConexionCdp $cdp, string $url): array
    {
        try {
            $cdp->comando('Browser.setDownloadBehavior', ['behavior' => 'deny']);
        } catch (RuntimeException) {
            // Algunas versiones no lo admiten; las descargas siguen sin red de todas formas.
        }
        $contexto = $cdp->comando('Target.createBrowserContext', ['disposeOnDetach' => true])['browserContextId'] ?? null;
        $objetivo = $cdp->comando('Target.createTarget', ['url' => 'about:blank', 'browserContextId' => $contexto])['targetId'] ?? null;
        $sesion = $cdp->comando('Target.attachToTarget', ['targetId' => $objetivo, 'flatten' => true])['sessionId'] ?? null;
        if (!is_string($sesion)) {
            throw new RuntimeException('No se pudo abrir una pestaña aislada.');
        }

        $cdp->alRecibir('Fetch.requestPaused', function (array $p, ?string $s) use ($cdp): void {
            $this->atender($cdp, $p, $s);
        });
        $cdp->alRecibir('Page.javascriptDialogOpening', static function (array $p, ?string $s) use ($cdp): void {
            $cdp->comando('Page.handleJavaScriptDialog', ['accept' => false], $s);
        });
        $cdp->comando('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]], $sesion);
        $cdp->comando('Network.enable', [], $sesion);
        $cdp->comando('Network.setBypassServiceWorker', ['bypass' => true], $sesion);
        $cdp->comando('Network.setCacheDisabled', ['cacheDisabled' => true], $sesion);
        $cdp->comando('Page.enable', [], $sesion);
        $cdp->comando('Emulation.setFocusEmulationEnabled', ['enabled' => true], $sesion);
        $this->ventana($cdp, $sesion, self::ANCHO, self::ALTO);

        $navegacion = $cdp->comando('Page.navigate', ['url' => $url], $sesion, $this->quedan());
        if (isset($navegacion['errorText']) && $navegacion['errorText'] !== '') {
            throw new RuntimeException('El navegador no pudo abrir la página: ' . $this->motivoDocumento());
        }
        $this->marcoPrincipal = is_string($navegacion['frameId'] ?? null) ? $navegacion['frameId'] : null;
        $this->esperarCarga($cdp);

        $mundo = $cdp->comando('Page.createIsolatedWorld', ['frameId' => $this->marcoPrincipal, 'worldName' => 'proconseo', 'grantUniveralAccess' => false], $sesion);
        $idMundo = $mundo['executionContextId'] ?? null;
        if (!is_int($idMundo)) {
            throw new RuntimeException('No se pudo preparar la medición.');
        }
        $js = (string) file_get_contents(__DIR__ . '/medir.js');
        $this->evaluar($cdp, $sesion, $idMundo, 'globalThis.__pf = ' . $js . '; true');

        $medidas = [];
        $medidas['dom'] = $this->evaluar($cdp, $sesion, $idMundo, '__pf.dom(' . $this->maxBytesDom . ')');
        $medidas['palabras'] = $this->evaluar($cdp, $sesion, $idMundo, '__pf.palabras()');

        // Contraste con una ventana alta, para que todo el texto quede a la vista.
        $alto = (int) $this->evaluar($cdp, $sesion, $idMundo, '__pf.alto()');
        if ($alto > self::ALTO_MAXIMO_MEDICION) {
            $this->limitaciones[] = 'Contraste: solo se mide el texto de los primeros ' . self::ALTO_MAXIMO_MEDICION . ' px de la página.';
        }
        $this->ventana($cdp, $sesion, self::ANCHO, max(self::ALTO, min($alto, self::ALTO_MAXIMO_MEDICION)));
        $this->esperarCalma($cdp, 0.4);
        $medidas['contraste'] = $this->evaluar($cdp, $sesion, $idMundo, '__pf.contraste()');
        $this->ventana($cdp, $sesion, self::ANCHO, self::ALTO);

        $medidas['foco'] = $this->recorrerFoco($cdp, $sesion, $idMundo);

        // 320 px de ancho equivalen a 1280 px con un zoom del 400 % (WCAG 1.4.10).
        $this->ventana($cdp, $sesion, 320, 640);
        $this->esperarCalma($cdp, 0.4);
        $medidas['reflow'] = $this->evaluar($cdp, $sesion, $idMundo, '__pf.reflow()');

        return $medidas;
    }

    /**
     * Pulsa Tab una y otra vez, como haría una persona con el teclado, y anota a dónde va el foco.
     *
     * @return array{enfocables: int, pasos: list<array<string, mixed>>, indicadores: list<?bool>, vueltaCompleta: bool}
     */
    private function recorrerFoco(ConexionCdp $cdp, string $sesion, int $idMundo): array
    {
        $enfocables = (int) $this->evaluar($cdp, $sesion, $idMundo, '__pf.empezarFoco()');
        $pasos = [];
        $vuelta = false;
        $repetidos = 0;
        for ($i = 0; $i < self::MAX_PASOS_FOCO && $this->quedan() > 2; $i++) {
            foreach (['keyDown', 'keyUp'] as $tipo) {
                $cdp->comando('Input.dispatchKeyEvent', ['type' => $tipo, 'key' => 'Tab', 'code' => 'Tab', 'windowsVirtualKeyCode' => 9, 'nativeVirtualKeyCode' => 9], $sesion);
            }
            /** @var array<string, mixed> $paso */
            $paso = $this->evaluar($cdp, $sesion, $idMundo, '__pf.paso()');
            if (($paso['fin'] ?? false) === true) {
                if ($pasos !== []) {
                    $vuelta = true;
                    break;
                }
                continue;
            }
            if (($paso['marco'] ?? false) === true) {
                $this->limitaciones[] = 'Foco: el contenido de los marcos (iframe) no se recorre.';
                continue;
            }
            if (($paso['primero'] ?? false) === true) {
                $vuelta = true;
                break;
            }
            $pasos[] = $paso;
            // Tres Tab seguidos sin moverse: el foco está atrapado; no hace falta seguir.
            $repetidos = ($paso['mismo'] ?? false) === true ? $repetidos + 1 : 0;
            if ($repetidos >= 3) {
                break;
            }
        }
        if (!$vuelta && $repetidos < 3) {
            $this->limitaciones[] = 'Foco: se recorren como mucho ' . self::MAX_PASOS_FOCO . ' elementos con el tabulador.';
        }
        /** @var list<?bool> $indicadores */
        $indicadores = $this->evaluar($cdp, $sesion, $idMundo, '__pf.terminarFoco()');

        return ['enfocables' => $enfocables, 'pasos' => $pasos, 'indicadores' => $indicadores, 'vueltaCompleta' => $vuelta];
    }

    /**
     * Atiende una petición interceptada: la permite (descargándola con el cliente seguro) o la corta.
     *
     * @param array<string, mixed> $p
     */
    private function atender(ConexionCdp $cdp, array $p, ?string $sesion): void
    {
        $id = (string) ($p['requestId'] ?? '');
        /** @var array<string, mixed> $peticion */
        $peticion = is_array($p['request'] ?? null) ? $p['request'] : [];
        $url = (string) ($peticion['url'] ?? '');
        $metodo = (string) ($peticion['method'] ?? 'GET');
        $tipo = (string) ($p['resourceType'] ?? 'Other');
        $esPrincipal = $this->marcoPrincipal === null || ($p['frameId'] ?? null) === $this->marcoPrincipal;

        $cortar = function (string $motivo, string $razon = 'BlockedByClient') use ($cdp, $id, $sesion, $url, $tipo): void {
            $this->anotar($url, $tipo, false, $motivo);
            $cdp->comando('Fetch.failRequest', ['requestId' => $id, 'errorReason' => $razon], $sesion);
        };

        try {
            // El primer documento del marco principal es la página (Chrome puede normalizar la
            // URL); después, solo las redirecciones que ya validó el cliente.
            $esperado = $tipo === 'Document' && $esPrincipal
                && (($this->documentosEsperados === [] && $this->documento === null) || isset($this->documentosEsperados[$url]));
            if ($esperado) {
                $this->documentosEsperados[$url] = true;
            }
            $decision = $this->filtro->decidir($metodo, $tipo, $url, $esperado);
            if (!$decision['permitir']) {
                $cortar($decision['motivo']);

                return;
            }
            if (count($this->peticiones) >= $this->maxPeticiones || $this->bytes >= $this->maxBytes || $this->quedan() <= 1) {
                $this->limitar('Se alcanzó el límite de peticiones, bytes o tiempo de la página: el resto no se descarga.');
                $cortar('Límite de la página alcanzado.');

                return;
            }

            $recurso = $this->cache[$url] ?? null;
            if ($recurso === null) {
                $recurso = $decision['clase'] === FiltroPeticiones::SUBRECURSO
                    ? $this->clienteSubrecursos->descargar($url, 'navegador: ' . $tipo, null, $decision['maxBytes'])
                    : ($decision['clase'] === FiltroPeticiones::DOCUMENTO
                        ? $this->clientePagina->obtener($url, 'navegador: página')
                        : $this->clientePagina->descargar($url, 'navegador: ' . $tipo, null, $decision['maxBytes']));
                $this->bytes += $recurso->bytes;
                $this->cache[$url] = $recurso;
            }

            if ($recurso->urlFinal !== $url && $recurso->urlFinal !== '' && ($recurso->esCorrecto() || $recurso->resultado === ResultadoDescarga::ErrorHttp)) {
                // El cliente ya siguió (y validó) las redirecciones: el navegador va directo al destino.
                $this->cache[$recurso->urlFinal] = $recurso;
                if ($tipo === 'Document') {
                    $this->documentosEsperados[$recurso->urlFinal] = true;
                }
                $this->anotar($url, $tipo, true, 'Redirección a ' . $recurso->urlFinal);
                $cdp->comando('Fetch.fulfillRequest', ['requestId' => $id, 'responseCode' => 302, 'responseHeaders' => [['name' => 'Location', 'value' => $recurso->urlFinal]], 'body' => ''], $sesion);

                return;
            }

            if ($tipo === 'Document' && $esPrincipal) {
                $this->documento = $recurso;
            }
            if (!$recurso->esCorrecto() && !($recurso->resultado === ResultadoDescarga::ErrorHttp && $recurso->estadoHttp !== null)) {
                $cortar(($recurso->motivo ?? $recurso->resultado->value), $recurso->resultado === ResultadoDescarga::BloqueadoSeguridad ? 'AccessDenied' : 'Failed');

                return;
            }
            $this->anotar($url, $tipo, true, $decision['motivo']);
            $cdp->comando('Fetch.fulfillRequest', [
                'requestId' => $id,
                'responseCode' => $recurso->estadoHttp ?? 200,
                'responseHeaders' => self::cabeceras($recurso),
                'body' => base64_encode($recurso->cuerpo),
            ], $sesion);
        } catch (Throwable $e) {
            // Ante cualquier fallo, la petición se corta: nunca se deja salir.
            try {
                $cortar('Fallo al atender la petición: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'error interno'), 'Failed');
            } catch (Throwable) {
                // El navegador ya no responde; se cerrará al terminar.
            }
        }
    }

    /** @return list<array{name: string, value: string}> */
    private static function cabeceras(RecursoDescargado $r): array
    {
        // El cuerpo ya viene descomprimido y entero: estas cabeceras ya no le corresponden.
        $fuera = ['content-encoding', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'set-cookie', 'set-cookie2', 'alt-svc', 'strict-transport-security'];
        $lista = [];
        foreach ($r->cabeceras as $nombre => $valor) {
            if (!in_array($nombre, $fuera, true)) {
                $lista[] = ['name' => $nombre, 'value' => $valor];
            }
        }
        if ($r->tipoContenido !== null && !isset($r->cabeceras['content-type'])) {
            $lista[] = ['name' => 'content-type', 'value' => $r->tipoContenido];
        }

        return $lista;
    }

    private function esperarCarga(ConexionCdp $cdp): void
    {
        while (($evento = $cdp->siguienteEvento(max(0.1, $this->quedan() - 15.0))) !== null) {
            if ($evento['method'] === 'Page.loadEventFired') {
                $this->esperarCalma($cdp, 0.8);

                return;
            }
        }
        $this->limitaciones[] = 'La página no terminó de cargar a tiempo: se mide lo que había cargado.';
    }

    /** Espera a que el navegador deje de pedir cosas durante $segundos (o a que se acabe el tiempo). */
    private function esperarCalma(ConexionCdp $cdp, float $segundos): void
    {
        $tope = min(microtime(true) + 8.0, $this->limite - 5.0);
        while (microtime(true) < $tope) {
            $antes = count($this->peticiones);
            $cdp->siguienteEvento($segundos);
            $cdp->olvidarEventos();
            if (count($this->peticiones) === $antes) {
                return;
            }
        }
    }

    private function ventana(ConexionCdp $cdp, string $sesion, int $ancho, int $alto): void
    {
        $cdp->comando('Emulation.setDeviceMetricsOverride', ['width' => $ancho, 'height' => $alto, 'deviceScaleFactor' => 1, 'mobile' => false], $sesion);
    }

    private function evaluar(ConexionCdp $cdp, string $sesion, int $idMundo, string $expresion): mixed
    {
        $r = $cdp->comando('Runtime.evaluate', ['expression' => $expresion, 'contextId' => $idMundo, 'returnByValue' => true, 'awaitPromise' => false, 'timeout' => 10000], $sesion, max(1.0, min(15.0, $this->quedan())));
        if (isset($r['exceptionDetails'])) {
            throw new RuntimeException('La medición falló dentro del navegador.');
        }

        return $r['result']['value'] ?? null;
    }

    private function anotar(string $url, string $tipo, bool $permitida, string $motivo): void
    {
        $this->peticiones[] = ['url' => mb_substr($url, 0, 300), 'tipo' => $tipo, 'permitida' => $permitida, 'motivo' => $motivo];
    }

    private function limitar(string $texto): void
    {
        if (!in_array($texto, $this->limitaciones, true)) {
            $this->limitaciones[] = $texto;
        }
    }

    private function motivoDocumento(): string
    {
        foreach (array_reverse($this->peticiones) as $p) {
            if ($p['tipo'] === 'Document' && !$p['permitida']) {
                return $p['motivo'];
            }
        }

        return 'no se pudo descargar.';
    }

    private function documentoServido(): ?RecursoDescargado
    {
        return $this->documento;
    }

    private function quedan(): float
    {
        return $this->limite - microtime(true);
    }
}
