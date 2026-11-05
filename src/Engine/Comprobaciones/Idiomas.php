<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-15: versiones por idioma (hreflang). Solo aplica si la página declara hreflang.
 */
final class Idiomas implements Comprobacion
{
    /** Errores de región frecuentes: el código ISO 3166-1 correcto. */
    private const REGIONES_ERRONEAS = ['UK' => 'GB', 'EN' => null, 'LA' => null, 'EU' => null];

    public function subcomprobaciones(): array
    {
        return [
            'SEO-15.hreflang-codigos' => Cobertura::Implementada,
            'SEO-15.hreflang-autorreferencia' => Cobertura::Implementada,
            'SEO-15.hreflang-destinos' => Cobertura::Implementada,
            'SEO-15.hreflang-reciprocidad' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        $alternativas = $e->documento()->alternativasIdioma;
        if ($alternativas === []) {
            return $e->resultado($id)->noAplicable('La página no declara versiones en otros idiomas (hreflang).');
        }
        return match ($id) {
            'SEO-15.hreflang-codigos' => $this->codigos($e, $alternativas),
            'SEO-15.hreflang-autorreferencia' => $this->autorreferencia($e, $alternativas),
            'SEO-15.hreflang-destinos' => $this->destinos($e, $alternativas),
            'SEO-15.hreflang-reciprocidad' => $this->reciprocidad($e, $alternativas),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** null si el código es válido; si no, el motivo. */
    public static function errorCodigo(string $codigo): ?string
    {
        if (strtolower($codigo) === 'x-default') {
            return null;
        }
        if (preg_match('/^([a-zA-Z]{2,3})(?:-([a-zA-Z]{4}))?(?:-([a-zA-Z]{2}))?$/', $codigo, $m) !== 1) {
            return '«' . $codigo . '» no es un código de idioma ISO 639-1 (p. ej. «es», «en-GB»)';
        }
        $region = strtoupper($m[3] ?? '');
        if ($region !== '' && array_key_exists($region, self::REGIONES_ERRONEAS)) {
            $correcta = self::REGIONES_ERRONEAS[$region];
            return '«' . $codigo . '»: «' . $region . '» no es un código de país ISO 3166-1' . ($correcta !== null ? ' (debe ser «' . $correcta . '»)' : '');
        }
        if (strlen($m[1]) === 3) {
            return '«' . $codigo . '»: Google solo admite códigos de idioma de dos letras (ISO 639-1)';
        }
        return null;
    }

    /** @param list<array{hreflang: string, href: string, url: ?string}> $alternativas */
    private function codigos(Entorno $e, array $alternativas): BorradorResultado
    {
        $errores = [];
        $porCodigo = [];
        foreach ($alternativas as $alt) {
            $error = self::errorCodigo($alt['hreflang']);
            if ($error !== null) {
                $errores[] = $error;
            }
            if ($alt['url'] === null) {
                $errores[] = '«' . $alt['hreflang'] . '» apunta a una dirección no válida: ' . $alt['href'];
            }
            $porCodigo[strtolower($alt['hreflang'])][] = (string) $alt['url'];
        }
        foreach ($porCodigo as $codigo => $urls) {
            if (count(array_unique($urls)) > 1) {
                $errores[] = 'El código «' . $codigo . '» apunta a varias URLs: ' . implode(', ', array_unique($urls));
            }
        }
        $v = $e->resultado('SEO-15.hreflang-codigos')
            ->valor(implode(', ', array_map(static fn (array $a): string => $a['hreflang'], $alternativas)));
        if ($errores === []) {
            return $v->correcto();
        }
        return $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $errores)))
            ->consecuencia('Google ignora los hreflang con códigos incorrectos: puede mostrar a cada usuario la versión del idioma equivocado.')
            ->correccion('Usar códigos ISO 639-1 de idioma y, si se indica país, ISO 3166-1 (p. ej. «es», «en», «en-GB»; nunca «espanol» ni «en-UK»).')
            ->problema(Gravedad::Medio);
    }

    /** @param list<array{hreflang: string, href: string, url: ?string}> $alternativas */
    private function autorreferencia(Entorno $e, array $alternativas): BorradorResultado
    {
        $propias = array_values(array_unique(array_filter([Url::sinFragmento($e->url()), $e->documento()->canonicos[0] ?? null])));
        $v = $e->resultado('SEO-15.hreflang-autorreferencia');
        foreach ($alternativas as $alt) {
            if ($alt['url'] !== null && in_array(Url::sinFragmento($alt['url']), $propias, true)) {
                return $v->valor('La página se incluye como «' . $alt['hreflang'] . '».')->correcto();
            }
        }
        return $v->valor('Ningún hreflang apunta a esta página.')
            ->consecuencia('Google exige que cada versión se incluya a sí misma en la lista; si no, puede ignorar el conjunto.')
            ->correccion('Añadir un <link rel="alternate" hreflang="(idioma de esta página)" href="' . Url::sinFragmento($e->url()) . '">.')
            ->problema(Gravedad::Medio);
    }

    /** @param list<array{hreflang: string, href: string, url: ?string}> $alternativas */
    private function destinos(Entorno $e, array $alternativas): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'comprobar los destinos hreflang');
        $v = $e->resultado('SEO-15.hreflang-destinos');
        $mal = [];
        $bien = 0;
        $sinComprobar = [];
        foreach ($this->otras($e, $alternativas) as $url) {
            try {
                $r = $e->obtener($url, 'versión en otro idioma (hreflang)', 'SEO-15.hreflang-destinos');
            } catch (NoVerificable) {
                $sinComprobar[] = $url;
                continue;
            }
            if ($r->estadoHttp === null) {
                $sinComprobar[] = $url;
            } elseif ($r->redirecciones !== []) {
                $mal[] = $url . ' redirige a ' . $r->urlFinal;
            } elseif ($r->estadoHttp >= 400) {
                $mal[] = $url . ' responde HTTP ' . $r->estadoHttp;
            } else {
                $bien++;
            }
        }
        $v->valor($bien . ' destino(s) correctos, ' . count($mal) . ' con errores, ' . count($sinComprobar) . ' sin comprobar.');
        if ($mal !== []) {
            return $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $mal)))
                ->consecuencia('Los hreflang que apuntan a redirecciones o errores no se tienen en cuenta.')
                ->correccion('Apuntar cada hreflang a la URL final de esa versión, que debe responder 200.')
                ->problema(Gravedad::Medio);
        }
        if ($bien === 0) {
            throw new NoVerificable('No se pudo comprobar ningún destino hreflang (¿otro dominio fuera del ámbito?).');
        }
        return $v->correcto();
    }

    /** @param list<array{hreflang: string, href: string, url: ?string}> $alternativas */
    private function reciprocidad(Entorno $e, array $alternativas): BorradorResultado
    {
        $e->exigeAlcance(Alcance::UrlContexto, 'comprobar que las otras versiones enlazan de vuelta');
        $v = $e->resultado('SEO-15.hreflang-reciprocidad');
        $propia = Url::sinFragmento($e->url());
        $analizador = new AnalizadorHtml();
        $sinVuelta = [];
        $conVuelta = 0;
        foreach ($this->otras($e, $alternativas) as $url) {
            try {
                $r = $e->obtener($url, 'versión en otro idioma (hreflang)', 'SEO-15.hreflang-reciprocidad');
                if (!$r->esCorrecto() || $r->estadoHttp === null || $r->estadoHttp >= 300) {
                    continue;
                }
                $doc = $analizador->analizar($r->cuerpo, $r->urlFinal);
            } catch (NoVerificable|ErrorExtraccion) {
                continue;
            }
            $vuelve = false;
            foreach ($doc->alternativasIdioma as $alt) {
                if ($alt['url'] !== null && Url::sinFragmento($alt['url']) === $propia) {
                    $vuelve = true;
                }
            }
            $vuelve ? $conVuelta++ : $sinVuelta[] = $url;
        }
        if ($sinVuelta === [] && $conVuelta === 0) {
            throw new NoVerificable('No se pudo leer ninguna de las otras versiones.');
        }
        $v->valor($conVuelta . ' versión(es) enlazan de vuelta; ' . count($sinVuelta) . ' no.');
        if ($sinVuelta === []) {
            return $v->correcto();
        }
        return $v->evidencia($e->evidencia(TipoEvidencia::Texto, "No enlazan de vuelta a $propia:\n" . implode("\n", $sinVuelta)))
            ->consecuencia('Si la otra versión no enlaza de vuelta, Google ignora la pareja de hreflang.')
            ->correccion('Añadir en cada versión la lista completa de hreflang, incluida esta página.')
            ->problema(Gravedad::Medio);
    }

    /**
     * URLs de las otras versiones (sin la propia ni x-default repetido).
     *
     * @param list<array{hreflang: string, href: string, url: ?string}> $alternativas
     * @return list<string>
     */
    private function otras(Entorno $e, array $alternativas): array
    {
        $propia = Url::sinFragmento($e->url());
        $urls = [];
        foreach ($alternativas as $alt) {
            if ($alt['url'] !== null && Url::sinFragmento($alt['url']) !== $propia) {
                $urls[Url::sinFragmento($alt['url'])] = true;
            }
        }
        return array_keys($urls);
    }
}
