<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * ACC-03 (estructura y título de la página), ACC-06 (enlaces y navegación con teclado,
 * lo comprobable sin navegador) y ACC-10 (idioma).
 */
final class AccesibilidadEstructura implements Comprobacion
{
    /** Texto o dirección de un enlace a la declaración de accesibilidad (es, ca, gl, eu, en). */
    private const DECLARACION = '/accesibilidad|accessibilitat|accesibilidade|irisgarritasun|accessibility/u';

    /** El texto del enlace dice que es una declaración (no, p. ej., un servicio de accesibilidad). */
    private const ES_DECLARACION = '/declaraci[oó]n|declaraci[oó]|adierazpen|statement/u';

    /** Enlaces genéricos cuyo propósito depende del contexto (2.4.4). */
    private const GENERICOS = ['aquí', 'aqui', 'pincha aquí', 'haz clic aquí', 'clic aquí', 'click aquí', 'leer más', 'leer mas',
        'más', 'ver más', 'más información', 'saber más', 'enlace', 'link', 'click here', 'here', 'read more', 'more'];

    public function subcomprobaciones(): array
    {
        return [
            'ACC-03.title-pagina' => Cobertura::Implementada,
            'ACC-03.estructura-semantica' => Cobertura::ParcialmenteImplementada,
            'ACC-03.tablas-encabezados' => Cobertura::ParcialmenteImplementada,
            'ACC-03.declaracion-accesibilidad' => Cobertura::Implementada,
            'ACC-06.enlaces-nombre' => Cobertura::Implementada,
            'ACC-06.enlaces-proposito' => Cobertura::ParcialmenteImplementada,
            'ACC-06.saltar-bloques' => Cobertura::ParcialmenteImplementada,
            'ACC-06.tabindex-positivo' => Cobertura::Implementada,
            'ACC-10.lang-presente' => Cobertura::Implementada,
            'ACC-10.lang-valido' => Cobertura::Implementada,
            'ACC-10.idioma-partes' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'ACC-03.title-pagina' => $this->titulo($entorno),
            'ACC-03.estructura-semantica' => $this->estructura($entorno),
            'ACC-03.tablas-encabezados' => $this->tablas($entorno),
            'ACC-03.declaracion-accesibilidad' => $this->declaracion($entorno),
            'ACC-06.enlaces-nombre' => $this->enlacesNombre($entorno),
            'ACC-06.enlaces-proposito' => $this->enlacesProposito($entorno),
            'ACC-06.saltar-bloques' => $this->saltarBloques($entorno),
            'ACC-06.tabindex-positivo' => $this->tabindex($entorno),
            'ACC-10.lang-presente' => $this->langPresente($entorno),
            'ACC-10.lang-valido' => $this->langValido($entorno),
            'ACC-10.idioma-partes' => $this->idiomaPartes($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** Código de idioma BCP 47 bien formado (idioma de 2-3 letras, script, región, variantes). */
    public static function codigoIdiomaValido(string $lang): bool
    {
        return preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z]{4})?(-([a-zA-Z]{2}|\d{3}))?(-([a-zA-Z0-9]{5,8}|\d[a-zA-Z0-9]{3}))*$/', $lang) === 1;
    }

    private function titulo(Entorno $e): BorradorResultado
    {
        $titulo = $e->documento()->titulo();
        $v = $e->resultado('ACC-03.title-pagina');
        if ($titulo === null || $titulo === '') {
            $e->exigeHtmlCompleto('el título de la página');
            return $v->valor($titulo === null ? 'La página no tiene <title>.' : 'El <title> está vacío.')
                ->consecuencia('Quien usa lector de pantalla no sabe en qué página está al abrirla ni al cambiar de pestaña.')
                ->correccion('Añadir un <title> que identifique la página y el sitio (ver SEO-03).')
                ->problema(Gravedad::Medio);
        }
        return $v->valor($titulo)
            ->limitacion('Que el título describa el tema o el propósito de la página lo valora una persona.')
            ->correcto();
    }

    private function estructura(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $principal = ($xp->query('//main|//*[@role="main"]')?->length ?? 0) > 0;
        $navegacion = ($xp->query('//nav|//*[@role="navigation"]')?->length ?? 0) > 0;
        $encabezados = count($doc->encabezados);
        $listas = $xp->query('//ul|//ol')?->length ?? 0;
        $v = $e->resultado('ACC-03.estructura-semantica')
            ->valor(sprintf('Región principal: %s; navegación: %s; %d encabezados; %d listas.', $principal ? 'sí' : 'no', $navegacion ? 'sí' : 'no', $encabezados, $listas))
            ->limitacion('Sin navegador no se puede comprobar que todo lo que parece un título o una lista esté marcado como tal.');

        $faltan = [];
        if (!$principal) {
            $faltan[] = 'la región principal (<main>)';
        }
        if ($encabezados === 0) {
            $faltan[] = 'encabezados (h1–h6)';
        }
        if ($faltan === []) {
            return $v->correcto();
        }
        $e->exigeHtmlCompleto('la estructura de la página');
        return $v->consecuencia('Las personas que usan lector de pantalla saltan por regiones y encabezados: sin ellos tienen que escuchar la página entera.')
            ->correccion('Marcar ' . implode(' y ', $faltan) . '.')
            ->mejorable();
    }

    private function tablas(Entorno $e): BorradorResultado
    {
        $xp = new DOMXPath($e->documento()->dom);
        $datos = [];
        foreach ($xp->query('//table[not(@role="presentation") and not(@role="none")]') ?: [] as $t) {
            if (!$t instanceof DOMElement) {
                continue;
            }
            $filas = $xp->query('.//tr', $t)?->length ?? 0;
            $maxCeldas = 0;
            foreach ($xp->query('.//tr', $t) ?: [] as $tr) {
                $maxCeldas = max($maxCeldas, $xp->query('./td|./th', $tr)?->length ?? 0);
            }
            if ($filas >= 2 && $maxCeldas >= 2) {
                $datos[] = [
                    'tabla' => $t,
                    'cabeceras' => ($xp->query('.//th', $t)?->length ?? 0) > 0,
                    'caption' => ($xp->query('./caption', $t)?->length ?? 0) > 0,
                    'resumen' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t->textContent)), 0, 80),
                ];
            }
        }
        $v = $e->resultado('ACC-03.tablas-encabezados');
        if ($datos === []) {
            return $v->noAplicable('No hay tablas con varias filas y columnas.');
        }
        $sinCabeceras = array_values(array_filter($datos, static fn (array $t): bool => !$t['cabeceras']));
        $v->valor(count($datos) . ' tabla(s); ' . count($sinCabeceras) . ' sin celdas de encabezado (<th>).');
        if ($sinCabeceras === []) {
            return $v->limitacion('Hay <th>; que estén bien asociados a sus datos (scope, headers) en tablas complejas lo revisa una persona.')->correcto();
        }
        return $v->elemento('<table> «' . $sinCabeceras[0]['resumen'] . '…»')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_map(static fn (array $t): string => 'Tabla sin <th>: ' . $t['resumen'], $sinCabeceras))))
            ->consecuencia('Si es una tabla de datos, el lector de pantalla lee los números sin decir a qué fila o columna pertenecen.')
            ->correccion('Marcar la fila o columna de títulos con <th scope="col"> o <th scope="row">. Si la tabla solo sirve para maquetar, añadir role="presentation".')
            ->revisionHumana('Hay que confirmar si es una tabla de datos o solo de maquetación.')
            ->noVerificable('Tabla sin encabezados: falta confirmar si contiene datos.');
    }

    /**
     * ACC-03.declaracion-accesibilidad (Real Decreto 1112/2018, art. 13): la web enlaza su
     * declaración de accesibilidad. Se busca un enlace cuyo texto o dirección hable de
     * accesibilidad (en castellano, catalán, gallego, euskera o inglés).
     */
    private function declaracion(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('ACC-03.declaracion-accesibilidad');
        $candidatos = [];
        foreach ($e->documento()->enlaces as $a) {
            if (!$a['tieneHref'] || $a['noNavegable'] || str_starts_with(trim($a['href']), '#')) {
                continue;
            }
            $texto = mb_strtolower($a['nombre'] . ' ' . rawurldecode($a['href']));
            if (preg_match(self::DECLARACION, $texto) === 1) {
                $candidatos[] = $a;
            }
        }

        if ($candidatos !== []) {
            $declaraciones = array_values(array_filter($candidatos, static fn (array $a): bool => preg_match(self::ES_DECLARACION, mb_strtolower($a['nombre'] . ' ' . rawurldecode($a['href']))) === 1));
            $a = $declaraciones[0] ?? $candidatos[0];
            $v->valor('Enlace «' . mb_substr($a['nombre'] !== '' ? $a['nombre'] : $a['href'], 0, 120) . '» → ' . ($a['url'] ?? $a['href']))
                ->elemento('<a href="' . $a['href'] . '">' . mb_substr($a['nombre'], 0, 120) . '</a>');
            $limitacion = 'Solo se comprueba que el enlace existe; el contenido de la declaración (fecha, nivel de cumplimiento, contacto) lo revisa una persona.';
            if ($declaraciones === []) {
                $limitacion .= ' El enlace habla de accesibilidad pero no dice «declaración»: conviene confirmar que lo sea.';
            }
            return $v->limitacion($limitacion)->correcto();
        }

        $e->exigeHtmlCompleto('un enlace a la declaración de accesibilidad');
        return $v
            ->explicacion('La web no enlaza ninguna declaración de accesibilidad: una página que explica qué partes son accesibles y cómo pedir ayuda.')
            ->valor('Ningún enlace de la página habla de accesibilidad.')
            ->consecuencia('En el sector público es obligatoria (Real Decreto 1112/2018). En el resto es recomendable: muestra el compromiso y da un contacto a quien encuentre barreras.')
            ->correccion('Publicar una página «Declaración de accesibilidad» y enlazarla desde el pie de todas las páginas.')
            ->correccionCms([
                'wordpress' => 'Crear la página en Páginas > Añadir nueva y enlazarla en Apariencia > Menús (menú del pie) o en el widget del pie.',
                'prestashop' => 'Crear la página en Diseño > Páginas (CMS) y añadirla al bloque de enlaces del pie.',
            ])
            ->limitacion('Solo se revisan los enlaces de esta página; la declaración puede estar enlazada desde otra. Que sea obligatoria depende de si la web es del sector público: hay que decidirlo con el cliente.')
            ->decisionRevisar();
    }

    private function enlacesNombre(Entorno $e): BorradorResultado
    {
        $enlaces = array_values(array_filter($e->documento()->enlaces, static fn (array $a): bool => $a['tieneHref']));
        $v = $e->resultado('ACC-06.enlaces-nombre');
        if ($enlaces === []) {
            return $v->noAplicable('La página no tiene enlaces.');
        }
        $sinNombre = array_values(array_filter($enlaces, static fn (array $a): bool => $a['nombre'] === ''));
        if ($sinNombre === []) {
            return $v->valor('Los ' . count($enlaces) . ' enlaces tienen nombre.')->correcto();
        }
        $lista = array_map(static fn (array $a): string => '<a href="' . $a['href'] . '"> (sin texto)', $sinNombre);
        return $v->valor(count($sinNombre) . ' de ' . count($enlaces) . ' enlaces sin nombre.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('El lector de pantalla anuncia «enlace» sin decir adónde lleva; con el teclado se llega a él sin saber qué es.')
            ->correccion('Dar a cada enlace un texto, un alt en su imagen o un aria-label que diga el destino. Quitar los enlaces vacíos.')
            ->problema(Gravedad::Alto);
    }

    private function enlacesProposito(Entorno $e): BorradorResultado
    {
        $enlaces = array_values(array_filter($e->documento()->enlaces, static fn (array $a): bool => $a['tieneHref'] && $a['nombre'] !== ''));
        $v = $e->resultado('ACC-06.enlaces-proposito');
        if ($enlaces === []) {
            return $v->noAplicable('La página no tiene enlaces con nombre.');
        }
        $genericos = [];
        foreach ($enlaces as $a) {
            $nombre = mb_strtolower(trim((string) preg_replace('/[\s.…:!»›→]+$/u', '', $a['nombre'])));
            if (in_array($nombre, self::GENERICOS, true)) {
                $genericos[] = '«' . $a['nombre'] . '» → ' . $a['href'];
            }
        }
        if ($genericos === []) {
            return $v->valor('Ningún enlace usa solo textos genéricos.')
                ->limitacion('No se valora si cada texto describe bien su destino.')
                ->correcto();
        }
        return $v->valor(count($genericos) . ' enlace(s) con texto genérico.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($genericos, 0, 20))))
            ->correccion('Hacer que el texto del enlace diga el destino («Ver tarifas de fontanería» en lugar de «más»).')
            ->revisionHumana('WCAG 2.4.4 admite que el propósito se entienda por la frase que rodea al enlace: hay que comprobarlo.')
            ->noVerificable('Textos genéricos: falta comprobar si el contexto aclara el destino.');
    }

    private function saltarBloques(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $saltos = [];
        foreach (array_slice($doc->enlaces, 0, 5) as $a) {
            if (str_starts_with($a['href'], '#') && strlen($a['href']) > 1) {
                $destino = substr($a['href'], 1);
                if (($xp->query('//*[@id="' . str_replace('"', '', $destino) . '"]')?->length ?? 0) > 0) {
                    $saltos[] = $a['nombre'];
                }
            }
        }
        $principal = ($xp->query('//main|//*[@role="main"]')?->length ?? 0) > 0;
        $v = $e->resultado('ACC-06.saltar-bloques');
        if ($saltos !== [] || $principal || $doc->encabezados !== []) {
            $mecanismos = array_filter([
                $saltos !== [] ? 'enlace «' . $saltos[0] . '»' : null,
                $principal ? 'región <main>' : null,
                $doc->encabezados !== [] ? 'encabezados' : null,
            ]);
            return $v->valor('Mecanismos para saltar bloques: ' . implode(', ', $mecanismos) . '.')
                ->limitacion('Que el enlace de salto sea visible al recibir el foco necesita navegador.')
                ->correcto();
        }
        $e->exigeHtmlCompleto('ningún mecanismo para saltar bloques');
        return $v->valor('Sin enlace para saltar al contenido, sin <main> y sin encabezados.')
            ->consecuencia('Quien navega con teclado tiene que recorrer el menú entero en cada página antes de llegar al contenido.')
            ->correccion('Añadir al principio un enlace «Saltar al contenido» que lleve al <main id="contenido">, y marcar los encabezados.')
            ->problema(Gravedad::Medio);
    }

    private function tabindex(Entorno $e): BorradorResultado
    {
        $positivos = $e->documento()->tabindexPositivos;
        $v = $e->resultado('ACC-06.tabindex-positivo');
        if ($positivos === []) {
            return $v->valor('Ningún elemento cambia el orden del foco con tabindex mayor que 0.')->correcto();
        }
        $lista = array_map(static fn (array $t): string => '<' . $t['etiqueta'] . ' tabindex="' . $t['tabindex'] . '">', $positivos);
        return $v->valor(count($positivos) . ' elemento(s) con tabindex positivo.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('Un tabindex positivo hace que el tabulador salte a ese elemento antes que al resto, y el orden deja de seguir el de lectura.')
            ->correccion('Usar tabindex="0" (o quitarlo) y ordenar el HTML en el orden lógico.')
            ->revisionHumana('Hay que comprobar con el teclado si el orden resultante sigue teniendo sentido (2.4.3).')
            ->noVerificable('tabindex positivo detectado: falta comprobar el orden del foco.');
    }

    private function langPresente(Entorno $e): BorradorResultado
    {
        $lang = $e->documento()->lang;
        $v = $e->resultado('ACC-10.lang-presente');
        if ($lang === null || $lang === '') {
            return $v->valor('<html> sin atributo lang.')
                ->elemento('<html>')
                ->consecuencia('El lector de pantalla puede leer el texto en español con la pronunciación de otro idioma y no se entiende.')
                ->correccion('Añadir el idioma a la etiqueta html: <html lang="es">.')
                ->correccionCms([
                    'wordpress' => 'Ajustes > Generales > «Idioma del sitio»; el tema debe usar language_attributes() en la etiqueta <html>.',
                    'prestashop' => 'Internacional > Localización > Idiomas: revisar el código ISO del idioma; el tema lo usa en <html lang>.',
                ])
                ->problema(Gravedad::Medio);
        }
        return $v->valor('<html lang="' . $lang . '">')->correcto();
    }

    private function langValido(Entorno $e): BorradorResultado
    {
        $lang = $e->documento()->lang;
        $v = $e->resultado('ACC-10.lang-valido');
        if ($lang === null || $lang === '') {
            return $v->noAplicable('No hay atributo lang (ver ACC-10.lang-presente).');
        }
        if (self::codigoIdiomaValido($lang)) {
            return $v->valor('«' . $lang . '» es un código de idioma válido.')
                ->limitacion('No se comprueba que coincida con el idioma real del texto (ACC-10.lang-coincide).')
                ->correcto();
        }
        return $v->valor('«' . $lang . '» no es un código de idioma válido.')
            ->elemento('<html lang="' . $lang . '">')
            ->consecuencia('Con un código no válido, el lector de pantalla no sabe en qué idioma leer.')
            ->correccion('Usar un código BCP 47, p. ej. «es», «es-ES» o «en» (con guion, no con guion bajo).')
            ->problema(Gravedad::Medio);
    }

    private function idiomaPartes(Entorno $e): BorradorResultado
    {
        $partes = $e->documento()->idiomasPartes;
        $v = $e->resultado('ACC-10.idioma-partes');
        $invalidas = array_values(array_filter($partes, static fn (array $p): bool => $p['lang'] !== '' && !self::codigoIdiomaValido($p['lang'])));
        if ($invalidas !== []) {
            $lista = array_map(static fn (array $p): string => '<' . $p['etiqueta'] . ' lang="' . $p['lang'] . '">', $invalidas);
            return $v->valor(count($invalidas) . ' fragmento(s) con código de idioma no válido.')
                ->elemento($lista[0])
                ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
                ->consecuencia('El lector de pantalla no puede cambiar de pronunciación en esos fragmentos.')
                ->correccion('Corregir el atributo lang con un código BCP 47 (p. ej. lang="en").')
                ->problema(Gravedad::Bajo);
        }
        $v->revisionHumana('Hay que comprobar si quedan frases en otro idioma sin marcar con lang (3.1.2).');
        if ($partes === []) {
            return $v->noVerificable('Ningún fragmento declara otro idioma: falta comprobar si hay textos en otro idioma.');
        }
        return $v->valor(count($partes) . ' fragmento(s) marcados con su idioma, todos con códigos válidos.')
            ->noVerificable('Los fragmentos marcados son válidos; falta comprobar si hay otros sin marcar.');
    }
}
