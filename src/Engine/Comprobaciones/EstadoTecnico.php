<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Causas;
use ProconSEO\Engine\Cms;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-01: estado técnico, rastreabilidad e indexabilidad, HTTPS, contenido mixto, formularios
 * sin cifrar (especificación v1.3), noindex de desarrollo olvidado (6.7) y detección de CMS.
 */
final class EstadoTecnico implements Comprobacion
{
    /** Textos que suelen aparecer en una página de error servida con 200 (en minúsculas). */
    private const SENALES_ERROR = [
        'página no encontrada', 'pagina no encontrada', 'page not found', 'no se ha encontrado la página',
        'la página que buscas no existe', 'la pagina que buscas no existe', 'error 404', '404 not found',
        'not found', 'no existe o se ha movido',
    ];

    public function subcomprobaciones(): array
    {
        return [
            'SEO-01.estado-http' => Cobertura::Implementada,
            'SEO-01.https' => Cobertura::Implementada,
            'SEO-01.contenido-mixto' => Cobertura::Implementada,
            'SEO-01.formulario-sin-cifrar' => Cobertura::Implementada,
            'SEO-01.rastreable' => Cobertura::Implementada,
            'SEO-01.indexable' => Cobertura::Implementada,
            'SEO-01.soft-404' => Cobertura::ParcialmenteImplementada,
            'SEO-01.noindex-desarrollo' => Cobertura::Implementada,
            'SEO-01.cms-detectado' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'SEO-01.estado-http' => $this->estadoHttp($e),
            'SEO-01.https' => $this->https($e),
            'SEO-01.contenido-mixto' => $this->contenidoMixto($e),
            'SEO-01.formulario-sin-cifrar' => $this->formularioSinCifrar($e),
            'SEO-01.rastreable' => $this->rastreable($e),
            'SEO-01.indexable' => $this->indexable($e),
            'SEO-01.soft-404' => $this->soft404($e),
            'SEO-01.noindex-desarrollo' => $this->noindexDesarrollo($e),
            'SEO-01.cms-detectado' => $this->cms($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function estadoHttp(Entorno $e): BorradorResultado
    {
        $e->exigeRespuesta();
        $p = $e->pagina();
        $codigo = (int) $p->estadoHttp;
        $v = $e->resultado('SEO-01.estado-http')->valor('HTTP ' . $codigo)->evidencia($e->evidenciaHttp($p));

        if ($codigo >= 200 && $codigo < 300) {
            return $v->correcto();
        }
        if (in_array($codigo, [401, 403, 429], true)) {
            throw new NoVerificable("El servidor respondió HTTP $codigo al analizador; eso no demuestra qué recibe Googlebot.");
        }
        if ($codigo >= 300 && $codigo < 400) {
            throw new NoVerificable("La descarga terminó en una redirección HTTP $codigo sin seguir (ver SEO-02).");
        }
        return $v
            ->consecuencia($codigo >= 500
                ? 'Con un error del servidor, Google no puede indexar la página y, si se repite, la retira de los resultados.'
                : 'Google no indexa una URL que responde ' . $codigo . ' y retira la que ya tuviera.')
            ->correccion($codigo === 404 || $codigo === 410
                ? 'Si la página se ha movido, redirigir con 301 a la nueva dirección; si la URL es la correcta, restaurar la página.'
                : 'Revisar los registros del servidor y corregir el fallo.')
            ->limitacion($codigo >= 500 ? 'Un solo análisis no demuestra que el error sea persistente.' : 'Comprobado una vez.')
            ->problema(Gravedad::Alto);
    }

    private function https(Entorno $e): BorradorResultado
    {
        $e->exigePagina();
        $p = $e->pagina();
        $v = $e->resultado('SEO-01.https');

        if (Url::esquema($p->urlFinal) !== 'https') {
            return $v->valor('La página se sirve por HTTP: ' . $p->urlFinal)
                ->evidencia($e->evidenciaHttp($p))
                ->consecuencia('Los navegadores la marcan como «No segura» y cualquier dato que se envíe viaja sin cifrar. Google da preferencia a las páginas HTTPS.')
                ->correccion('Instalar un certificado (p. ej., Let\'s Encrypt) y redirigir con 301 todo el tráfico HTTP a HTTPS.')
                ->problema(Gravedad::Alto);
        }

        $hayHttpRedirigido = false;
        foreach ($p->redirecciones as $salto) {
            if (Url::esquema($salto['url']) === 'http' && Url::esquema((string) $salto['destino']) === 'https') {
                $hayHttpRedirigido = true;
            }
        }
        $v->valor('HTTPS con certificado válido.')->evidencia($e->evidenciaHttp($p));
        if ($hayHttpRedirigido) {
            return $v->valor('HTTPS con certificado válido; la versión HTTP redirige a HTTPS.')->correcto();
        }

        try {
            $e->exigeAlcance(Alcance::UrlContexto, 'comprobar que la versión HTTP redirige a HTTPS');
        } catch (NoVerificable $nv) {
            return $v->limitacion($nv->getMessage())->correcto();
        }

        $http = 'http://' . substr($p->urlFinal, strlen('https://'));
        $r = $e->obtener($http, 'versión HTTP de la página', 'SEO-01.https');
        $v->evidencia($e->evidenciaHttp($r));
        $primerSalto = $r->redirecciones[0] ?? null;

        if ($primerSalto !== null && Url::esquema((string) $primerSalto['destino']) === 'https') {
            return $v->valor('HTTPS con certificado válido; la versión HTTP redirige a HTTPS (' . $primerSalto['estado'] . ').')->correcto();
        }
        if ($r->esCorrecto() && $r->estadoHttp !== null && $r->estadoHttp < 300 && Url::esquema($r->urlFinal) === 'http') {
            return $v->valor('La versión HTTP (' . $http . ') responde ' . $r->estadoHttp . ' sin redirigir a HTTPS.')
                ->consecuencia('Quien entra por http:// navega sin cifrar y Google puede ver dos versiones de la misma página.')
                ->correccion('Redirigir con 301 todas las URLs http:// a su versión https://.')
                ->problema(Gravedad::Medio);
        }
        return $v->limitacion('No se pudo comprobar la versión HTTP: ' . Entorno::describirDescarga($r) . '.')->correcto();
    }

    private function contenidoMixto(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-01.contenido-mixto');
        if (Url::esquema($e->url()) !== 'https') {
            return $v->noAplicable('La página no se sirve por HTTPS (ver SEO-01.https).');
        }
        $mixtos = array_values(array_filter($doc->recursos, static fn (array $r): bool => Url::esquema($r['url']) === 'http'));
        if ($mixtos === []) {
            return $v->valor('Todos los recursos (' . count($doc->recursos) . ') se cargan por HTTPS.')->correcto();
        }
        $activos = array_filter($mixtos, static fn (array $r): bool => in_array($r['tipo'], ['script', 'estilo', 'iframe', 'objeto'], true));
        $lista = array_map(static fn (array $r): string => $r['tipo'] . ': ' . $r['url'], array_slice($mixtos, 0, 20));

        return $v
            ->valor(count($mixtos) . ' recurso(s) por HTTP en una página HTTPS' . ($activos !== [] ? ', ' . count($activos) . ' de ellos scripts, estilos o iframes' : '') . '.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $lista)))
            ->consecuencia($activos !== []
                ? 'Los navegadores bloquean los scripts, estilos e iframes por HTTP: partes de la página pueden no funcionar o verse mal, y aparece el aviso de conexión no segura.'
                : 'Los navegadores muestran la página como no totalmente segura y pueden bloquear esas imágenes o medios.')
            ->correccion('Cambiar «http://» por «https://» en las direcciones de esos recursos (o usar rutas relativas).')
            ->correccionCms([
                'wordpress' => 'Ajustes > Generales: comprobar que «Dirección de WordPress» y «Dirección del sitio» empiezan por https://. Para las URLs antiguas del contenido, usar un plugin de búsqueda y reemplazo (p. ej. Better Search Replace) de http:// a https://.',
                'prestashop' => 'Parámetros de la tienda > General: activar SSL y «Activar SSL en todas las páginas».',
            ])
            ->problema($activos !== [] ? Gravedad::Alto : Gravedad::Medio);
    }

    private function formularioSinCifrar(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-01.formulario-sin-cifrar');
        $formularios = array_values(array_filter($doc->formularios, static fn (array $f): bool => $f['metodo'] !== 'ninguno'));
        if ($formularios === []) {
            return $v->noAplicable('La página no tiene formularios.');
        }
        $inseguros = array_values(array_filter($formularios, static fn (array $f): bool => $f['url'] !== null && Url::esquema($f['url']) === 'http'));
        if ($inseguros === []) {
            return $v->valor(count($formularios) . ' formulario(s), todos envían a direcciones HTTPS.')->correcto();
        }
        $lista = array_map(static fn (array $f): string => '<form action="' . ($f['action'] ?? '') . '" method="' . $f['metodo'] . '"> → ' . $f['url'], $inseguros);

        return $v
            ->valor(count($inseguros) . ' formulario(s) envían los datos por HTTP.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
            ->consecuencia('Lo que escribe el usuario (nombre, correo, teléfono…) viaja sin cifrar y se puede leer por el camino. Los navegadores avisan al enviarlo.')
            ->correccion('Hacer que el atributo action apunte a una dirección https:// (o dejarlo relativo en una página HTTPS).')
            ->problema(Gravedad::Alto);
    }

    private function rastreable(Entorno $e): BorradorResultado
    {
        $p = $e->permisoRobots('Googlebot');
        $v = $e->resultado('SEO-01.rastreable')
            ->valor('Googlebot: ' . ($p['permitido'] ? 'permitido' : 'bloqueado') . ($p['regla'] !== null ? ' (línea ' . $p['linea'] . ', «' . $p['regla'] . '»)' : ''));
        if ($p['permitido']) {
            return $v->correcto();
        }
        if ($e->pideNoIndexar()) {
            return $v->limitacion('Coincide con el objetivo declarado de no indexar.')->correcto();
        }
        return $v
            ->evidencia($e->evidencia(TipoEvidencia::Texto, 'robots.txt línea ' . $p['linea'] . ': ' . $p['regla'], $e->robots()['recurso']->urlSolicitada))
            ->consecuencia('Google no puede leer la página: no la mostrará con su contenido en los resultados.')
            ->correccion('Si no es intencionado, quitar o acotar la regla Disallow de robots.txt.')
            ->revisionHumana('Bloquear el rastreo puede ser deliberado.')
            ->decisionRevisar();
    }

    private function indexable(Entorno $e): BorradorResultado
    {
        $fuentes = DirectivasIndexacion::fuentesNoindex($e);
        $v = $e->resultado('SEO-01.indexable');
        if ($fuentes === []) {
            return $v->valor('Sin noindex en la meta robots ni en X-Robots-Tag.')->correcto();
        }
        $v->valor(implode('; ', $fuentes))->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $fuentes)));
        if ($e->pideNoIndexar()) {
            return $v->limitacion('Coincide con el objetivo declarado de no indexar.')->correcto();
        }
        return $v
            ->consecuencia('La página no aparecerá en Google ni en Bing.')
            ->correccion('Si debe aparecer en buscadores, quitar el noindex.')
            ->revisionHumana('Un noindex puede ser deliberado: hay que confirmarlo.')
            ->decisionRevisar();
    }

    private function soft404(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-01.soft-404');
        $titulares = mb_strtolower(implode(' | ', array_merge($doc->titulos, array_column(array_filter($doc->encabezados, static fn (array $h): bool => $h['nivel'] <= 2), 'texto'))));
        foreach (self::SENALES_ERROR as $senal) {
            if (str_contains($titulares, $senal)) {
                return $v
                    ->valor('Responde ' . $e->pagina()->estadoHttp . ' pero el título o el encabezado dicen «' . $senal . '».')
                    ->elemento(mb_substr($titulares, 0, 300))
                    ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Título y encabezados: ' . $titulares))
                    ->conclusion(TipoConclusion::Inferencia)
                    ->consecuencia('Google puede tratarla como una página de error y no indexarla, o indexar una página vacía.')
                    ->correccion('Si la página no existe, responder 404 o 410; si existe, cambiar el texto del título y del encabezado.')
                    ->revisionHumana('El texto sugiere un error, pero puede ser una página que habla de errores 404.')
                    ->decisionRevisar();
            }
        }
        if (mb_strlen($doc->texto) < RespuestaUtilizable::TEXTO_MINIMO) {
            return $v->noVerificable('La página tiene muy poco texto en el HTML inicial: no se puede descartar que sea una página de error.');
        }
        return $v->valor('Responde ' . $e->pagina()->estadoHttp . ' con contenido propio y sin textos de error en el título ni en los encabezados.')
            ->conclusion(TipoConclusion::Inferencia)
            ->limitacion('Solo se revisan el título y los encabezados, sin navegador.')
            ->correcto();
    }

    private function noindexDesarrollo(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('SEO-01.noindex-desarrollo');
        if (!$e->esKitDigital()) {
            return $v->noAplicable('Solo se valora en proyectos Web Kit Digital y Tienda Kit Digital.');
        }

        $motivos = DirectivasIndexacion::fuentesNoindex($e);
        $robotsVerificado = true;
        try {
            $p = $e->permisoRobots('Googlebot');
            if (!$p['permitido']) {
                $motivos[] = 'robots.txt bloquea a Googlebot (línea ' . $p['linea'] . ', «' . $p['regla'] . '»)';
            }
        } catch (NoVerificable) {
            $robotsVerificado = false;
        }

        if ($motivos === []) {
            if (!$robotsVerificado) {
                throw new NoVerificable('No hay noindex en la página, pero no se pudo comprobar robots.txt.');
            }
            return $v->valor('Sin noindex y con el rastreo permitido: la web está abierta a los buscadores.')->correcto();
        }

        $v->valor(implode('; ', $motivos))->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $motivos)));
        if ($e->pideNoIndexar()) {
            return $v->limitacion('El objetivo declarado es no indexar.')->correcto();
        }
        $wordpress = isset($e->cms()[Cms::WORDPRESS]);
        return $v
            ->explicacion('La web le dice a Google que no la muestre. Es lo que suele quedar activado por error al pasar una web de desarrollo a producción, y deja la web invisible en los buscadores.')
            ->consecuencia('La web no aparece en Google ni en Bing, lo que incumple el compromiso de indexación del Kit Digital.')
            ->correccion('Quitar el noindex' . (count($motivos) > 1 || str_contains($motivos[0], 'robots.txt') ? ' y la regla «Disallow: /» de robots.txt' : '') . ' antes de entregar la web.')
            ->correccionCms([
                'wordpress' => 'Ajustes > Lectura: desmarcar «Disuadir a los motores de búsqueda de indexar este sitio» y guardar.',
                'prestashop' => 'Revisar el fichero robots.txt (Parámetros de la tienda > Tráfico y SEO > Generar robots.txt) y el módulo SEO del tema.',
            ])
            ->limitacion($wordpress ? 'WordPress detectado: es la causa más habitual.' : 'Se ha supuesto que no es intencionado porque no hay objetivo «no indexar» declarado.')
            ->causa(DirectivasIndexacion::fuentesNoindex($e) !== [] ? Causas::DIRECTIVAS_NOINDEX : Causas::ROBOTS_BLOQUEA)
            ->problema(Gravedad::Critico);
    }

    private function cms(Entorno $e): BorradorResultado
    {
        $e->documento();
        $detectados = $e->cms();
        $v = $e->resultado('SEO-01.cms-detectado')->limitacion('Informativo: sirve para dar instrucciones concretas de corrección.');
        if ($detectados === []) {
            return $v->noAplicable('No se detecta WordPress, WooCommerce ni PrestaShop.');
        }
        $partes = [];
        foreach ($detectados as $cms => $senales) {
            $partes[] = Cms::nombre($cms) . ' (' . implode(', ', $senales) . ')';
        }
        return $v->valor(implode('; ', $partes))
            ->conclusion(TipoConclusion::Inferencia)
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $partes)))
            ->correcto();
    }
}
