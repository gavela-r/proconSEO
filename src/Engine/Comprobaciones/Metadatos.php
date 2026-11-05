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
 * SEO-03 (title), SEO-04 (meta description), SEO-05 (encabezados) y SEO-10.viewport.
 *
 * Solo se valora lo objetivo: presencia, unicidad y estructura. Que el texto sea adecuado
 * lo valorarán IA o una persona (title-descriptivo, description-coherente…): la presencia
 * nunca se presenta como calidad (especificación 10).
 */
final class Metadatos implements Comprobacion
{
    public function subcomprobaciones(): array
    {
        return [
            'SEO-03.title-presente' => Cobertura::Implementada,
            'SEO-03.title-unico' => Cobertura::Implementada,
            'SEO-03.title-longitud' => Cobertura::Implementada,
            'SEO-04.description-presente' => Cobertura::Implementada,
            'SEO-04.description-unica' => Cobertura::Implementada,
            'SEO-05.h1-presente' => Cobertura::Implementada,
            'SEO-05.estructura-encabezados' => Cobertura::Implementada,
            'SEO-05.encabezados-vacios' => Cobertura::Implementada,
            'SEO-10.viewport' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'SEO-03.title-presente' => $this->titlePresente($e),
            'SEO-03.title-unico' => $this->titleUnico($e),
            'SEO-03.title-longitud' => $this->titleLongitud($e),
            'SEO-04.description-presente' => $this->descriptionPresente($e),
            'SEO-04.description-unica' => $this->descriptionUnica($e),
            'SEO-05.h1-presente' => $this->h1($e),
            'SEO-05.estructura-encabezados' => $this->estructura($e),
            'SEO-05.encabezados-vacios' => $this->vacios($e),
            'SEO-10.viewport' => $this->viewport($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function titlePresente(Entorno $e): BorradorResultado
    {
        $titulo = $e->documento()->titulo();
        $v = $e->resultado('SEO-03.title-presente');
        if ($titulo === null || $titulo === '') {
            $e->exigeHtmlCompleto('el title');
            return $v->valor($titulo === null ? 'No hay elemento <title>.' : 'El <title> está vacío.')
                ->elemento($titulo === null ? null : '<title></title>')
                ->evidencia($titulo === null ? null : $e->evidencia(TipoEvidencia::FragmentoHtml, '<title></title>'))
                ->consecuencia('Sin title, Google inventa el título del resultado a partir de otros textos de la página, y la pestaña del navegador no dice qué es.')
                ->correccion('Añadir en el <head> un <title> que diga qué ofrece la página y de quién es, p. ej. «Servicio – Empresa en Ciudad».')
                ->correccionCms([
                    'wordpress' => 'Editar la página y rellenar el «Título SEO» en Yoast o Rank Math; si no hay plugin SEO, revisar que el tema incluya add_theme_support(\'title-tag\').',
                    'prestashop' => 'Catálogo > Productos (o Páginas) > pestaña SEO: rellenar «Meta título».',
                ])
                ->problema(Gravedad::Medio);
        }
        return $v->valor($titulo)
            ->elemento('<title>' . $titulo . '</title>')
            ->limitacion('Solo se comprueba que existe; si describe bien la página lo valora SEO-03.title-descriptivo.')
            ->correcto();
    }

    private function titleUnico(Entorno $e): BorradorResultado
    {
        $titulos = $e->documento()->titulos;
        $v = $e->resultado('SEO-03.title-unico');
        if ($titulos === []) {
            return $v->noAplicable('No hay title (ver SEO-03.title-presente).');
        }
        if (count($titulos) === 1) {
            return $v->valor('Un único <title>.')->correcto();
        }
        $fragmento = implode("\n", array_map(static fn (string $t): string => '<title>' . $t . '</title>', $titulos));
        return $v->valor(count($titulos) . ' elementos <title>.')
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, $fragmento))
            ->consecuencia('Los buscadores usan normalmente el primero; los demás sobran y pueden mostrar un título que no se esperaba.')
            ->correccion('Dejar un solo <title> en el <head>. Suele deberse a que el tema y un plugin SEO añaden cada uno el suyo.')
            ->mejorable();
    }

    private function titleLongitud(Entorno $e): BorradorResultado
    {
        $titulo = $e->documento()->titulo();
        $v = $e->resultado('SEO-03.title-longitud');
        if ($titulo === null || $titulo === '') {
            return $v->noAplicable('No hay title.');
        }
        return $v->valor(mb_strlen($titulo) . ' caracteres.')
            ->limitacion('Dato auxiliar: la longitud no es un problema por sí sola. Google corta en pantalla los títulos largos (aprox. 600 px), pero los sigue leyendo.')
            ->correcto();
    }

    private function descriptionPresente(Entorno $e): BorradorResultado
    {
        $lista = $e->documento()->metasPorNombre('description');
        $v = $e->resultado('SEO-04.description-presente');
        $texto = trim($lista[0] ?? '');
        if ($texto === '') {
            $e->exigeHtmlCompleto('la meta description');
            return $v->valor($lista === [] ? 'No hay meta description.' : 'La meta description está vacía.')
                ->consecuencia('Google elegirá por su cuenta qué trozo de la página mostrar bajo el título.')
                ->correccion('Añadir <meta name="description" content="…"> con un resumen de lo que el usuario encontrará en la página.')
                ->correccionCms([
                    'wordpress' => 'Rellenar la «Meta descripción» de la página en Yoast o Rank Math.',
                    'prestashop' => 'Pestaña SEO del producto o página: rellenar «Meta descripción».',
                ])
                ->mejorable();
        }
        return $v->valor($texto)
            ->limitacion('Solo se comprueba que existe; su coherencia con el contenido la valora SEO-04.description-coherente.')
            ->correcto();
    }

    private function descriptionUnica(Entorno $e): BorradorResultado
    {
        $lista = $e->documento()->metasPorNombre('description');
        $v = $e->resultado('SEO-04.description-unica');
        if ($lista === []) {
            return $v->noAplicable('No hay meta description.');
        }
        if (count($lista) === 1) {
            return $v->valor('Una única meta description.')->correcto();
        }
        $fragmento = implode("\n", array_map(static fn (string $d): string => '<meta name="description" content="' . $d . '">', $lista));
        return $v->valor(count($lista) . ' meta description.')
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, $fragmento))
            ->consecuencia('Con varias descripciones, el buscador puede ignorarlas o elegir la que no se quería.')
            ->correccion('Dejar una sola meta description. Suele deberse a que el tema y un plugin SEO añaden cada uno la suya.')
            ->mejorable();
    }

    private function h1(Entorno $e): BorradorResultado
    {
        $h1 = array_values(array_filter($e->documento()->encabezados, static fn (array $h): bool => $h['nivel'] === 1));
        $conTexto = array_values(array_filter($h1, static fn (array $h): bool => $h['texto'] !== ''));
        $v = $e->resultado('SEO-05.h1-presente');
        if ($conTexto === []) {
            $e->exigeHtmlCompleto('el H1');
            return $v->valor($h1 === [] ? 'No hay ningún H1.' : 'El H1 está vacío.')
                ->consecuencia('El H1 es el titular de la página: sin él, buscadores y lectores de pantalla tienen menos claro de qué trata.')
                ->correccion('Marcar el titular principal de la página como <h1> (uno que diga de qué trata la página).')
                ->problema(Gravedad::Bajo);
        }
        return $v->valor($conTexto[0]['texto'])
            ->elemento('<h1>' . $conTexto[0]['texto'] . '</h1>')
            ->correcto();
    }

    private function estructura(Entorno $e): BorradorResultado
    {
        $encabezados = $e->documento()->encabezados;
        $v = $e->resultado('SEO-05.estructura-encabezados');
        if ($encabezados === []) {
            $e->exigeHtmlCompleto('ningún encabezado');
            return $v->valor('La página no tiene encabezados (h1–h6).')
                ->consecuencia('Sin encabezados, el contenido es un bloque sin estructura para buscadores, IA y lectores de pantalla.')
                ->correccion('Organizar el contenido con un <h1> y apartados <h2> (y <h3> dentro de ellos).')
                ->mejorable();
        }
        $observaciones = [];
        $numH1 = count(array_filter($encabezados, static fn (array $h): bool => $h['nivel'] === 1));
        if ($numH1 > 1) {
            $observaciones[] = $numH1 . ' H1';
        }
        $anterior = 0;
        foreach ($encabezados as $h) {
            if ($anterior > 0 && $h['nivel'] > $anterior + 1) {
                $observaciones[] = 'salto de h' . $anterior . ' a h' . $h['nivel'] . ' («' . mb_substr($h['texto'], 0, 60) . '»)';
            }
            $anterior = $h['nivel'];
        }
        $esquema = implode("\n", array_map(static fn (array $h): string => str_repeat('  ', $h['nivel'] - 1) . 'h' . $h['nivel'] . ': ' . $h['texto'], array_slice($encabezados, 0, 60)));
        $v->valor(count($encabezados) . ' encabezados' . ($observaciones !== [] ? '; ' . implode('; ', $observaciones) : '') . '.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, $esquema));
        if ($observaciones !== []) {
            $v->limitacion('Varios H1 o saltos de nivel no son errores por sí solos; se informan para revisar que la estructura tenga sentido.');
        }
        return $v->correcto();
    }

    private function vacios(Entorno $e): BorradorResultado
    {
        $encabezados = $e->documento()->encabezados;
        $v = $e->resultado('SEO-05.encabezados-vacios');
        if ($encabezados === []) {
            return $v->noAplicable('La página no tiene encabezados.');
        }
        $vacios = array_values(array_filter($encabezados, static fn (array $h): bool => $h['texto'] === ''));
        if ($vacios === []) {
            return $v->valor('Todos los encabezados (' . count($encabezados) . ') tienen texto.')->correcto();
        }
        $niveles = array_map(static fn (array $h): string => '<h' . $h['nivel'] . '></h' . $h['nivel'] . '>', $vacios);
        return $v->valor(count($vacios) . ' encabezado(s) vacío(s).')
            ->elemento($niveles[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $niveles)))
            ->consecuencia('Un lector de pantalla anuncia un encabezado sin nada que leer, y el esquema de la página queda incompleto.')
            ->correccion('Quitar los encabezados vacíos o darles texto. Si solo se usaban para dar espacio, sustituirlos por márgenes en el CSS.')
            ->problema(Gravedad::Bajo);
    }

    private function viewport(Entorno $e): BorradorResultado
    {
        $viewport = $e->documento()->viewport();
        $v = $e->resultado('SEO-10.viewport');
        if ($viewport === null || trim($viewport) === '') {
            $e->exigeHtmlCompleto('la meta viewport');
            return $v->valor('No hay <meta name="viewport">.')
                ->consecuencia('En el móvil la página se muestra como en un ordenador, en miniatura: hay que ampliar para leer. Google indexa la versión móvil.')
                ->correccion('Añadir en el <head>: <meta name="viewport" content="width=device-width, initial-scale=1">.')
                ->problema(Gravedad::Alto);
        }
        $v->valor($viewport)->elemento('<meta name="viewport" content="' . $viewport . '">');
        if (!preg_match('/width\s*=\s*device-width/i', $viewport)) {
            return $v->consecuencia('El ancho no se adapta a la pantalla del dispositivo.')
                ->correccion('Usar content="width=device-width, initial-scale=1".')
                ->problema(Gravedad::Medio);
        }
        return $v->limitacion('El viewport es necesario pero no suficiente: que el diseño se adapte de verdad lo mide el informe de PageSpeed en móvil.')->correcto();
    }
}
