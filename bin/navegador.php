<?php

declare(strict_types=1);

/**
 * PROTOTIPO del navegador aislado: pinta una página y mide contraste, foco con teclado,
 * reflow a 320 px y contenido que aparece tras JavaScript. No guarda nada en la base de datos.
 *
 *   php bin/navegador.php https://www.ejemplo.es/
 *   php bin/navegador.php https://www.ejemplo.es/ --json     mediciones en bruto
 *   php bin/navegador.php https://www.ejemplo.es/ --peticiones   además, qué se descargó y qué no
 *
 * Necesita Chrome o Edge instalado (o NAVEGADOR_RUTA en .env). El navegador no tiene red propia:
 * todo lo descarga ClienteHttpSeguro con las mismas validaciones que el resto del análisis.
 */

use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\Http\TransporteCurl;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\Navegador\FiltroPeticiones;
use ProconSEO\Fetch\Navegador\LecturaProvisional;
use ProconSEO\Fetch\Navegador\NavegadorAislado;
use ProconSEO\Fetch\Navegador\ProcesoNavegador;
use ProconSEO\Fetch\ResolvedorDnsSistema;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

$raiz = dirname(__DIR__);
Config::cargar($raiz);

$argumentos = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$json = in_array('--json', $argv, true);
$verPeticiones = in_array('--peticiones', $argv, true);
if (count($argumentos) !== 1) {
    fwrite(STDERR, "Uso: php bin/navegador.php <url> [--json] [--peticiones]\n");
    exit(2);
}

$ejecutable = ProcesoNavegador::buscarEjecutable(Config::obtener('NAVEGADOR_RUTA'));
if ($ejecutable === null) {
    fwrite(STDERR, "No se encuentra Chrome ni Edge. Instala uno o indica la ruta en NAVEGADOR_RUTA (.env).\n");
    exit(1);
}

// Límites del perfil de ejecución más reciente (config/perfiles/vN.json).
$perfiles = glob($raiz . '/config/perfiles/v*.json') ?: [];
natsort($perfiles);
$perfil = $perfiles === [] ? [] : json_decode((string) file_get_contents((string) end($perfiles)), true);
$limites = is_array($perfil) ? LimitesDescarga::desdePerfil($perfil) : new LimitesDescarga();

$validadorUrl = new ValidadorUrl($limites->puertosPermitidos);
try {
    $destino = $validadorUrl->validar($argumentos[0]);
} catch (DestinoRechazado $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$validador = new ValidadorDestino($validadorUrl, new ResolvedorDnsSistema());
$ambito = Ambito::deUrl($destino);

$navegador = new NavegadorAislado(
    $ejecutable,
    new ClienteHttpSeguro(new TransporteCurl(), $validador, $ambito, $limites),
    new ClienteHttpSeguro(new TransporteCurl(), $validador, Ambito::subrecursos(), $limites),
    new FiltroPeticiones($ambito, $validadorUrl),
);
$pagina = $navegador->analizar($destino->url());

if ($json) {
    echo json_encode([
        'url' => $pagina->url, 'error' => $pagina->error, 'segundos' => $pagina->segundos,
        'palabrasVisibles' => $pagina->palabrasVisibles, 'contraste' => $pagina->contraste, 'foco' => $pagina->foco,
        'reflow' => $pagina->reflow, 'peticiones' => $pagina->peticiones, 'limitaciones' => $pagina->limitaciones,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
    exit($pagina->esCorrecta() ? 0 : 1);
}

$permitidas = count(array_filter($pagina->peticiones, static fn (array $p): bool => $p['permitida']));
echo "Navegador aislado (prototipo): {$pagina->url}" . PHP_EOL;
echo "Tiempo: {$pagina->segundos} s · Peticiones: {$permitidas} hechas, " . (count($pagina->peticiones) - $permitidas) . ' cortadas' . PHP_EOL . PHP_EOL;

foreach (LecturaProvisional::leer($pagina) as $r) {
    $estado = $r['estado']->value . ($r['gravedad'] !== null ? ' (' . $r['gravedad']->value . ')' : '');
    echo "[{$estado}] {$r['titulo']}" . PHP_EOL . '    ' . $r['detalle'] . PHP_EOL;
    foreach ($r['evidencias'] as $e) {
        echo '      - ' . $e . PHP_EOL;
    }
    echo PHP_EOL;
}
if ($pagina->limitaciones !== []) {
    echo 'Limitaciones:' . PHP_EOL;
    foreach ($pagina->limitaciones as $l) {
        echo '  - ' . $l . PHP_EOL;
    }
}
if ($verPeticiones) {
    echo PHP_EOL . 'Peticiones:' . PHP_EOL;
    foreach ($pagina->peticiones as $p) {
        echo ($p['permitida'] ? '  [ok]    ' : '  [corta] ') . str_pad($p['tipo'], 11) . ' ' . $p['url'] . ($p['permitida'] ? '' : '  → ' . $p['motivo']) . PHP_EOL;
    }
}
exit($pagina->esCorrecta() ? 0 : 1);
