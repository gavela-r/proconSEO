<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * Lo que las etiquetas <meta> imponen a quien usa la página, sin navegador:
 *
 * - ACC-04.zoom-bloqueado (WCAG 1.4.4), regla ACT b4f0c3: la meta viewport no impide ampliar.
 * - ACC-07.recarga-automatica (WCAG 2.2.1), regla ACT bc659a: no hay meta refresh con espera.
 */
final class AccesibilidadMeta implements Comprobacion
{
    /** ACT bc659a: una espera de más de 20 horas no se considera un límite de tiempo. */
    public const REFRESH_MAXIMO = 72000;

    /** ACT b4f0c3: hay que poder ampliar al menos al 200 %. */
    public const ESCALA_MINIMA = 2.0;

    public function subcomprobaciones(): array
    {
        return [
            'ACC-04.zoom-bloqueado' => Cobertura::Implementada,
            // Las recargas que programa JavaScript (setTimeout, location.reload) necesitan navegador.
            'ACC-07.recarga-automatica' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'ACC-04.zoom-bloqueado' => $this->zoom($entorno),
            'ACC-07.recarga-automatica' => $this->recarga($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Propiedades de la meta viewport («width=device-width, user-scalable=no»), en minúsculas.
     * Se admiten comas, punto y coma y espacios como separadores, como hacen los navegadores.
     *
     * @return array<string, string>
     */
    public static function propiedadesViewport(string $contenido): array
    {
        $propiedades = [];
        foreach (preg_split('/[,;]+/', strtolower($contenido)) ?: [] as $parte) {
            $par = explode('=', $parte, 2);
            $clave = trim($par[0]);
            if ($clave !== '') {
                $propiedades[$clave] = trim($par[1] ?? '');
            }
        }
        return $propiedades;
    }

    /**
     * Motivo por el que la viewport impide ampliar, o null si lo permite.
     */
    public static function bloqueoZoom(string $contenido): ?string
    {
        $p = self::propiedadesViewport($contenido);
        if (isset($p['user-scalable'])) {
            $valor = $p['user-scalable'];
            // Valores numéricos entre -1 y 1 equivalen a «no» (especificación CSS Device Adaptation).
            if ($valor === 'no' || (is_numeric($valor) && abs((float) $valor) < 1)) {
                return "user-scalable=$valor";
            }
        }
        if (isset($p['maximum-scale']) && is_numeric($p['maximum-scale']) && (float) $p['maximum-scale'] >= 0 && (float) $p['maximum-scale'] < self::ESCALA_MINIMA) {
            return 'maximum-scale=' . $p['maximum-scale'];
        }
        return null;
    }

    /**
     * Segundos de espera de un meta refresh («5; url=/otra») o null si el contenido no es válido.
     */
    public static function esperaRefresh(string $contenido): ?int
    {
        if (preg_match('/^\s*(\d+)(?:\.\d*)?\s*(?:$|[;,\s])/', $contenido, $m) !== 1) {
            return null;
        }
        return strlen($m[1]) > 9 ? PHP_INT_MAX : (int) $m[1];
    }

    private function zoom(Entorno $e): BorradorResultado
    {
        $viewport = $e->documento()->viewport();
        $v = $e->resultado('ACC-04.zoom-bloqueado');
        if ($viewport === null || trim($viewport) === '') {
            return $v->noAplicable('No hay meta viewport: el navegador deja ampliar la página.');
        }

        $elemento = '<meta name="viewport" content="' . $viewport . '">';
        $v->valor($viewport)->elemento($elemento);
        $bloqueo = self::bloqueoZoom($viewport);
        if ($bloqueo === null) {
            return $v->limitacion('Solo se mira la meta viewport; que el texto se lea bien al 200 % lo comprueba ACC-04.cambio-tamano-texto con navegador.')->correcto();
        }
        return $v
            ->explicacion('La página no deja ampliar con los dedos en el móvil. Quien ve mal necesita hacer zoom para leer.')
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, $elemento))
            ->consecuencia("Con $bloqueo, las personas con baja visión no pueden ampliar el texto en el móvil.")
            ->correccion('Quitar user-scalable=no y maximum-scale de la meta viewport; basta con content="width=device-width, initial-scale=1".')
            ->correccionCms([
                'wordpress' => 'Suele venir del tema o de un plugin de «modo app»: buscar «user-scalable» en header.php del tema hijo o en los ajustes del plugin.',
                'prestashop' => 'Editar la plantilla head.tpl del tema y quitar user-scalable y maximum-scale de la meta viewport.',
            ])
            ->problema(Gravedad::Alto);
    }

    private function recarga(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('ACC-07.recarga-automatica');

        // ACT bc659a: cuenta el primer meta refresh con contenido válido.
        $refresh = null;
        $espera = null;
        foreach ($doc->metas as $meta) {
            if (strtolower((string) $meta['httpEquiv']) !== 'refresh' || $meta['contenido'] === null) {
                continue;
            }
            $espera = self::esperaRefresh($meta['contenido']);
            if ($espera !== null) {
                $refresh = $meta['contenido'];
                break;
            }
        }
        $v->limitacion('Solo se mira el meta refresh del HTML; las recargas que programa JavaScript necesitan navegador.');

        if ($refresh === null || $espera === null) {
            return $v->valor('Sin meta refresh.')->correcto();
        }

        $elemento = '<meta http-equiv="refresh" content="' . $refresh . '">';
        $v->valor($refresh)->elemento($elemento);
        if ($espera === 0) {
            return $v->valor($refresh . ' (redirección inmediata: la valora SEO-02)')->correcto();
        }
        if ($espera > self::REFRESH_MAXIMO) {
            return $v->valor($refresh . ' (más de 20 horas: no es un límite de tiempo)')->correcto();
        }
        $destino = preg_match('/url\s*=/i', $refresh) === 1 ? 'lleva a otra página' : 'se recarga';
        return $v
            ->explicacion("La página $destino sola a los $espera segundos. Quien lee despacio o usa un lector de pantalla pierde lo que estaba leyendo.")
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, $elemento))
            ->consecuencia("A los $espera segundos la página $destino sin que la persona pueda pararlo ni pedir más tiempo.")
            ->correccion('Quitar el meta refresh. Si la página se ha movido, usar una redirección 301 en el servidor; si hay que actualizar datos, hacerlo sin recargar o con un botón.')
            ->problema(Gravedad::Medio);
    }
}
