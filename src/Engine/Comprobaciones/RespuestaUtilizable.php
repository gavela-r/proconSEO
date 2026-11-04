<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-03: respuesta utilizable y contenido principal recuperable (sin navegador).
 */
final class RespuestaUtilizable implements Comprobacion
{
    /** Por debajo de este texto visible no se puede afirmar que el contenido esté en el HTML inicial. */
    public const TEXTO_MINIMO = 200;

    /** Señales públicas de muros anti-robots (en minúsculas). */
    private const SENALES_MURO = [
        'g-recaptcha' => 'reCAPTCHA', 'h-captcha' => 'hCaptcha', 'hcaptcha.com' => 'hCaptcha',
        'cf-challenge' => 'desafío de Cloudflare', 'challenge-platform' => 'desafío de Cloudflare',
        'cf-turnstile' => 'Cloudflare Turnstile', 'just a moment...' => 'página de espera anti-robots',
        'checking your browser' => 'comprobación del navegador', 'attention required!' => 'bloqueo de Cloudflare',
        'captcha' => 'CAPTCHA',
    ];

    /**
     * En una página que responde 2xx, reCAPTCHA o hCaptcha suelen ser parte de un formulario
     * legítimo: solo cuentan las señales de muro que sustituyen a todo el contenido.
     */
    private const SENALES_MURO_COMPLETO = ['cf-challenge', 'challenge-platform', 'just a moment...', 'checking your browser', 'attention required!'];

    private const TIPOS_HTML = ['text/html', 'application/xhtml+xml'];

    public function subcomprobaciones(): array
    {
        return [
            'IA-03.respuesta-http' => Cobertura::Implementada,
            'IA-03.bloqueo-acceso' => Cobertura::Implementada,
            'IA-03.contenido-html-inicial' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'IA-03.respuesta-http' => $this->respuesta($e),
            'IA-03.bloqueo-acceso' => $this->bloqueo($e),
            'IA-03.contenido-html-inicial' => $this->contenido($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function respuesta(Entorno $e): BorradorResultado
    {
        $e->exigeRespuesta();
        $p = $e->pagina();
        $v = $e->resultado('IA-03.respuesta-http')->evidencia($e->evidenciaHttp($p, ['content-type']));
        $codigo = (int) $p->estadoHttp;
        $tipo = strtolower(trim(explode(';', (string) ($p->tipoContenido ?? $p->cabecera('content-type')))[0]));

        if ($codigo >= 200 && $codigo < 300 && $p->esCorrecto()) {
            if ($tipo !== '' && !in_array($tipo, self::TIPOS_HTML, true)) {
                return $v->valor("HTTP $codigo con contenido «{$tipo}», no HTML.")
                    ->noAplicable('La URL no es una página HTML; este análisis valora páginas web.');
            }
            return $v->valor("HTTP $codigo" . ($tipo !== '' ? ", $tipo" : '') . '.')->correcto();
        }
        if (in_array($codigo, [401, 403, 429], true)) {
            throw new NoVerificable("El servidor respondió HTTP $codigo al analizador: no se puede saber qué recibe un buscador (ver IA-03.bloqueo-acceso).");
        }
        return $v->valor("HTTP $codigo: la página no se puede usar.")
            ->consecuencia('Un buscador o un asistente con IA que pida esta URL recibe un error en lugar del contenido.')
            ->correccion('Corregir el error del servidor o, si la página ya no existe, redirigir con 301 a la página equivalente.')
            ->limitacion($codigo >= 500 ? 'Un solo análisis no demuestra que el error sea persistente.' : 'Se ha comprobado una sola vez.')
            ->problema(Gravedad::Alto);
    }

    private function bloqueo(Entorno $e): BorradorResultado
    {
        $p = $e->pagina();
        if ($p->resultado === ResultadoDescarga::TiempoAgotado || $p->resultado === ResultadoDescarga::ErrorRed
            || $p->resultado === ResultadoDescarga::FueraDeAmbito || $p->resultado === ResultadoDescarga::BloqueadoSeguridad
            || $p->resultado === ResultadoDescarga::ErrorTls || $p->resultado === ResultadoDescarga::DemasiadoGrande) {
            throw new NoVerificable('No se pudo recuperar la página: ' . Entorno::describirDescarga($p) . '.');
        }

        $codigo = $p->estadoHttp;
        $esError = $codigo === null || $codigo >= 400;
        $muro = $this->muro($p->cuerpo, $esError);
        $v = $e->resultado('IA-03.bloqueo-acceso');

        if (in_array($codigo, [401, 403, 429], true) || ($muro !== null && $esError)) {
            $detalle = 'HTTP ' . ($codigo ?? '—') . ($muro !== null ? ' con ' . $muro : '');
            return $v->valor($detalle . '.')
                ->evidencia($e->evidenciaHttp($p, ['retry-after', 'server']))
                ->conclusion(TipoConclusion::Inferencia)
                ->consecuencia('Si los buscadores y asistentes con IA reciben el mismo bloqueo, no pueden leer la página.')
                ->correccion('Revisar el cortafuegos, la protección anti-bots o el CDN para que no bloqueen a Googlebot, bingbot ni a los buscadores con IA.')
                ->limitacion('El bloqueo se ha observado con el user-agent de proconSEO; no demuestra que afecte igual a cada buscador.')
                ->revisionHumana('Hay que confirmar si la protección distingue a los buscadores legítimos.')
                ->decisionRevisar();
        }
        if ($muro !== null) {
            return $v->valor('La página responde ' . $codigo . ' pero muestra ' . $muro . '.')
                ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Señal encontrada en el HTML: ' . $muro))
                ->conclusion(TipoConclusion::Inferencia)
                ->consecuencia('Los robots pueden recibir el desafío en lugar del contenido.')
                ->revisionHumana('La señal puede ser un formulario con CAPTCHA legítimo dentro de la página.')
                ->decisionRevisar();
        }
        $e->exigePagina();
        return $v->valor('HTTP ' . $codigo . ' sin señales de bloqueo ni de CAPTCHA.')->correcto();
    }

    private function contenido(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $longitud = mb_strlen($doc->texto);
        $v = $e->resultado('IA-03.contenido-html-inicial')->valor($longitud . ' caracteres de texto visible en el HTML inicial.');

        if ($longitud >= self::TEXTO_MINIMO) {
            return $v->limitacion('Sin navegador no se compara con la página renderizada (IA-03.contenido-renderizado).')->correcto();
        }

        $scripts = count(array_filter($doc->recursos, static fn (array $r): bool => $r['tipo'] === 'script'));
        return $v->noVerificable(
            'El HTML inicial apenas tiene texto' . ($scripts > 0 ? " y carga $scripts script(s)" : '')
            . ': el contenido puede aparecer al ejecutar JavaScript. Sin navegador no se puede afirmar que falte.',
        );
    }

    private function muro(string $cuerpo, bool $esError): ?string
    {
        if ($cuerpo === '') {
            return null;
        }
        $texto = strtolower(substr($cuerpo, 0, 200000));
        foreach (self::SENALES_MURO as $senal => $nombre) {
            if (!$esError && !in_array($senal, self::SENALES_MURO_COMPLETO, true)) {
                continue;
            }
            if (str_contains($texto, $senal)) {
                return $nombre;
            }
        }
        return null;
    }
}
