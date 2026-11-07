<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * PROTOTIPO: página HTML con el resultado del navegador aislado, para verlo en un navegador
 * (php bin/navegador.php <url> --html). Todo lo que viene de la web analizada se escapa (SEG-09).
 * Cuando se integre, los resultados se verán en las pantallas de la Parte 3 y esto desaparece.
 */
final class InformeHtml
{
    private const ETIQUETAS = [
        'correcto' => 'Correcto',
        'mejorable' => 'Mejorable',
        'problema' => 'Problema',
        'decision_revisar' => 'Decisión a revisar',
        'no_verificable' => 'No verificable',
        'no_aplicable' => 'No aplicable',
    ];

    /**
     * @param list<array{id: string, titulo: string, estado: EstadoResultado, gravedad: mixed, detalle: string, evidencias: list<string>}> $resultados
     */
    public static function generar(PaginaRenderizada $p, array $resultados): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $hechas = count(array_filter($p->peticiones, static fn (array $x): bool => $x['permitida']));
        $cortadas = count($p->peticiones) - $hechas;

        $tarjetas = '';
        foreach ($resultados as $r) {
            $estado = $r['estado']->value;
            $gravedad = $r['gravedad'] instanceof \BackedEnum ? ' · ' . $e((string) $r['gravedad']->value) : '';
            $evidencias = '';
            if ($r['evidencias'] !== []) {
                $evidencias = '<ul>' . implode('', array_map(static fn (string $x): string => '<li>' . $e($x) . '</li>', $r['evidencias'])) . '</ul>';
            }
            $tarjetas .= '<article class="resultado ' . $e($estado) . '">'
                . '<div class="cabecera"><span class="estado">' . $e(self::ETIQUETAS[$estado] ?? $estado) . $gravedad . '</span>'
                . '<code>' . $e($r['id']) . '</code></div>'
                . '<h3>' . $e($r['titulo']) . '</h3><p>' . $e($r['detalle']) . '</p>' . $evidencias . '</article>';
        }

        $limitaciones = $p->limitaciones === [] ? '' : '<section><h2>Limitaciones</h2><ul>'
            . implode('', array_map(static fn (string $x): string => '<li>' . $e($x) . '</li>', $p->limitaciones)) . '</ul></section>';

        $filas = '';
        foreach ($p->peticiones as $x) {
            $filas .= '<tr class="' . ($x['permitida'] ? 'ok' : 'corta') . '"><td>' . ($x['permitida'] ? 'Hecha' : 'Cortada') . '</td><td>' . $e($x['tipo'])
                . '</td><td class="url">' . $e($x['url']) . '</td><td>' . $e($x['permitida'] ? '' : $x['motivo']) . '</td></tr>';
        }

        $error = $p->error === null ? '' : '<p class="error">' . $e($p->error) . '</p>';
        $fecha = date('d/m/Y H:i');

        return <<<HTML
            <!doctype html>
            <html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Navegador aislado · {$e($p->url)}</title>
            <style>
              :root { --negro:#000; --rojo:#e30613; --gris:#6c6c6c; --fondo:#f5f5f5; --linea:#e2e2e2; }
              * { box-sizing: border-box; }
              body { margin:0; font: 15px/1.5 "Segoe UI", system-ui, sans-serif; color:var(--negro); background:#fff; }
              header { padding: 28px 32px 20px; border-bottom: 4px solid var(--rojo); }
              header small { color: var(--gris); text-transform: uppercase; letter-spacing: .08em; font-size: 12px; }
              h1 { margin: 4px 0 6px; font-size: 26px; }
              h1 a { color: inherit; word-break: break-all; }
              .datos { color: var(--gris); margin: 0; }
              main { padding: 24px 32px 48px; max-width: 1100px; }
              h2 { font-size: 18px; margin: 32px 0 12px; }
              .resultados { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
              .resultado { border: 1px solid var(--linea); border-left: 6px solid var(--gris); padding: 14px 16px; background: #fff; }
              .resultado h3 { margin: 6px 0 4px; font-size: 16px; }
              .resultado p { margin: 0; }
              .resultado ul { margin: 8px 0 0; padding-left: 18px; color: #333; font-size: 13px; }
              .cabecera { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
              .cabecera code { color: var(--gris); font-size: 12px; }
              .estado { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 3px; background: var(--fondo); }
              .correcto { border-left-color: #1a7f37; } .correcto .estado { background: #e6f4ea; color: #1a7f37; }
              .problema { border-left-color: var(--rojo); } .problema .estado { background: #fde7e9; color: #b00010; }
              .mejorable { border-left-color: #b25e00; } .mejorable .estado { background: #fff1e0; color: #8a4600; }
              .decision_revisar { border-left-color: #6b3fa0; } .decision_revisar .estado { background: #f0e8fa; color: #5a2f8a; }
              .no_verificable, .no_aplicable { border-left-color: var(--gris); }
              table { width: 100%; border-collapse: collapse; font-size: 13px; }
              th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--linea); vertical-align: top; }
              th { background: var(--fondo); }
              td.url { word-break: break-all; }
              tr.corta td:first-child { color: #b00010; font-weight: 600; }
              tr.ok td:first-child { color: #1a7f37; font-weight: 600; }
              .error { background: #fde7e9; color: #b00010; padding: 12px 16px; }
              footer { color: var(--gris); font-size: 12px; padding: 0 32px 32px; }
            </style></head>
            <body>
            <header>
              <small>proconSEO · Navegador aislado (prototipo)</small>
              <h1><a href="{$e($p->url)}" rel="noopener noreferrer">{$e($p->url)}</a></h1>
              <p class="datos">{$fecha} · {$e((string) $p->segundos)} s · {$hechas} peticiones hechas, {$cortadas} cortadas</p>
            </header>
            <main>
              {$error}
              <h2>Resultados</h2>
              <div class="resultados">{$tarjetas}</div>
              {$limitaciones}
              <section><h2>Peticiones del navegador</h2>
              <p class="datos">El navegador no tiene red propia: cada petición la revisa proconSEO y, si se permite, la descarga el cliente seguro.</p>
              <table><thead><tr><th>Estado</th><th>Tipo</th><th>Dirección</th><th>Motivo</th></tr></thead><tbody>{$filas}</tbody></table>
              </section>
            </main>
            <footer>Prototipo: estos resultados todavía no se guardan en el análisis.</footer>
            </body></html>
            HTML;
    }
}
