<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * ACC-09: formularios (etiquetas, placeholder, autocomplete, obligatorios y el formulario de
 * billetes del sector transporte). Los errores y la autenticación necesitan navegador.
 */
final class AccesibilidadFormularios implements Comprobacion
{
    /** Tipos de campo que no necesitan etiqueta propia. */
    private const SIN_ETIQUETA = ['hidden', 'submit', 'button', 'reset', 'image'];

    /** Valores de autocomplete de HTML para datos personales (1.3.5), más on/off. */
    private const AUTOCOMPLETE = [
        'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name', 'honorific-suffix', 'nickname',
        'username', 'new-password', 'current-password', 'one-time-code', 'organization-title', 'organization',
        'street-address', 'address-line1', 'address-line2', 'address-line3', 'address-level4', 'address-level3',
        'address-level2', 'address-level1', 'country', 'country-name', 'postal-code', 'cc-name', 'cc-given-name',
        'cc-additional-name', 'cc-family-name', 'cc-number', 'cc-exp', 'cc-exp-month', 'cc-exp-year', 'cc-csc',
        'cc-type', 'transaction-currency', 'transaction-amount', 'language', 'bday', 'bday-day', 'bday-month',
        'bday-year', 'sex', 'url', 'photo', 'tel', 'tel-country-code', 'tel-national', 'tel-area-code', 'tel-local',
        'tel-local-prefix', 'tel-local-suffix', 'tel-extension', 'email', 'impp',
    ];

    /** Señales de que un campo pide un dato personal: nombre o id => valor de autocomplete esperado. */
    private const DATOS_PERSONALES = [
        '/^(nombre|name|first.?name|nombre.?completo|fullname)$/i' => 'name',
        '/apellido|last.?name|surname/i' => 'family-name',
        '/e-?mail|correo/i' => 'email',
        '/tel[eé]fono|^tel$|phone|movil|m[oó]vil/i' => 'tel',
        '/direcci[oó]n|address|domicilio|calle/i' => 'street-address',
        '/c[oó]digo.?postal|^cp$|postal|zip/i' => 'postal-code',
        '/ciudad|localidad|poblaci[oó]n|city/i' => 'address-level2',
        '/empresa|company|organi[sz]ation/i' => 'organization',
    ];

    public function subcomprobaciones(): array
    {
        return [
            'ACC-09.labels-asociados' => Cobertura::Implementada,
            'ACC-09.placeholder-como-etiqueta' => Cobertura::Implementada,
            'ACC-09.autocomplete' => Cobertura::Implementada,
            'ACC-09.campos-obligatorios' => Cobertura::ParcialmenteImplementada,
            'ACC-09.formulario-billetes' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        $campos = $this->campos($e);
        if ($campos === [] && $id !== 'ACC-09.formulario-billetes') {
            return $e->resultado($id)->noAplicable('La página no tiene campos de formulario.');
        }
        return match ($id) {
            'ACC-09.labels-asociados' => $this->etiquetas($e, $campos),
            'ACC-09.placeholder-como-etiqueta' => $this->placeholder($e, $campos),
            'ACC-09.autocomplete' => $this->autocomplete($e, $campos),
            'ACC-09.campos-obligatorios' => $this->obligatorios($e, $campos),
            'ACC-09.formulario-billetes' => $this->billetes($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** @return list<array<string, mixed>> campos que el usuario rellena */
    private function campos(Entorno $e, ?array $formularios = null): array
    {
        $campos = [];
        foreach ($formularios ?? $e->documento()->formularios as $f) {
            foreach ($f['campos'] as $c) {
                if (!in_array($c['tipo'], self::SIN_ETIQUETA, true)) {
                    $campos[] = $c;
                }
            }
        }
        return $campos;
    }

    /** @param array<string, mixed> $c */
    private static function describir(array $c): string
    {
        $atributos = '';
        foreach (['type' => $c['elemento'] === 'input' ? $c['tipo'] : null, 'name' => $c['nombre'], 'id' => $c['id'], 'placeholder' => $c['placeholder']] as $k => $val) {
            if ($val !== null && $val !== '') {
                $atributos .= ' ' . $k . '="' . $val . '"';
            }
        }
        return '<' . $c['elemento'] . $atributos . '>';
    }

    /** @param array<string, mixed> $c */
    private static function tieneNombre(array $c): bool
    {
        return trim((string) $c['nombreAccesible']) !== '';
    }

    /** @param list<array<string, mixed>> $campos */
    private function etiquetas(Entorno $e, array $campos): BorradorResultado
    {
        $sin = array_values(array_filter($campos, static fn (array $c): bool => !self::tieneNombre($c)));
        $v = $e->resultado('ACC-09.labels-asociados');
        if ($sin === []) {
            return $v->valor('Los ' . count($campos) . ' campos tienen etiqueta.')
                ->limitacion('Que el texto de cada etiqueta sea claro lo revisa una persona.')
                ->correcto();
        }
        $lista = array_map([self::class, 'describir'], $sin);
        return $v->valor(count($sin) . ' de ' . count($campos) . ' campos sin etiqueta.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('Quien usa lector de pantalla oye «campo de texto» sin saber qué tiene que escribir; el formulario puede quedar imposible de rellenar.')
            ->correccion('Asociar a cada campo un <label for="id-del-campo">Texto</label>, o un aria-label si la etiqueta no puede ser visible.')
            ->correccionCms([
                'wordpress' => 'En Contact Form 7, envolver cada campo en <label>Nombre [text nombre]</label>; en otros constructores, activar «mostrar etiqueta» en cada campo.',
                'prestashop' => 'Revisar la plantilla del tema (formularios de contacto y de cliente) para que cada input tenga su <label for>.',
            ])
            ->problema(Gravedad::Alto);
    }

    /** @param list<array<string, mixed>> $campos */
    private function placeholder(Entorno $e, array $campos): BorradorResultado
    {
        $conPlaceholder = array_values(array_filter($campos, static fn (array $c): bool => trim((string) $c['placeholder']) !== ''));
        $v = $e->resultado('ACC-09.placeholder-como-etiqueta');
        if ($conPlaceholder === []) {
            return $v->noAplicable('Ningún campo usa placeholder.');
        }
        $soloPlaceholder = array_values(array_filter($conPlaceholder, static fn (array $c): bool => !self::tieneNombre($c)));
        if ($soloPlaceholder === []) {
            return $v->valor('Los campos con placeholder también tienen etiqueta.')->correcto();
        }
        $lista = array_map([self::class, 'describir'], $soloPlaceholder);
        return $v->valor(count($soloPlaceholder) . ' campo(s) cuya única indicación es el placeholder.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
            ->consecuencia('El placeholder desaparece al escribir, suele tener poco contraste y muchos lectores de pantalla no lo leen como etiqueta.')
            ->correccion('Añadir una etiqueta visible (<label>) y dejar el placeholder, si acaso, para un ejemplo.')
            ->problema(Gravedad::Medio);
    }

    /** @param list<array<string, mixed>> $campos */
    private function autocomplete(Entorno $e, array $campos): BorradorResultado
    {
        $personales = [];
        foreach ($campos as $c) {
            $esperado = match ($c['tipo']) {
                'email' => 'email',
                'tel' => 'tel',
                default => null,
            };
            $clave = trim(($c['nombre'] ?? '') . ' ' . ($c['id'] ?? ''));
            foreach (self::DATOS_PERSONALES as $patron => $valor) {
                if ($esperado === null && $clave !== '' && (preg_match($patron, (string) $c['nombre']) === 1 || preg_match($patron, (string) $c['id']) === 1)) {
                    $esperado = $valor;
                }
            }
            if ($esperado !== null) {
                $personales[] = ['campo' => $c, 'esperado' => $esperado];
            }
        }
        $v = $e->resultado('ACC-09.autocomplete');
        if ($personales === []) {
            return $v->noAplicable('No se detectan campos de datos personales (nombre, correo, teléfono, dirección…).');
        }
        $mal = [];
        foreach ($personales as $p) {
            $tokens = preg_split('/\s+/', strtolower(trim((string) $p['campo']['autocomplete']))) ?: [];
            $valido = array_intersect($tokens, self::AUTOCOMPLETE) !== [];
            if (!$valido) {
                $mal[] = self::describir($p['campo']) . ' → autocomplete="' . ($p['campo']['autocomplete'] ?? '') . '" (se esperaba «' . $p['esperado'] . '»)';
            }
        }
        $v->limitacion('Los datos personales se reconocen por el tipo de campo y por su name o id.');
        if ($mal === []) {
            return $v->valor('Los ' . count($personales) . ' campos de datos personales indican su propósito con autocomplete.')->correcto();
        }
        return $v->valor(count($mal) . ' de ' . count($personales) . ' campos de datos personales sin un autocomplete válido.')
            ->elemento($mal[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $mal)))
            ->consecuencia('El navegador no puede rellenar esos datos solo, algo que ayuda mucho a personas con dificultades motoras o cognitivas.')
            ->correccion('Añadir autocomplete con el valor de cada dato: name, email, tel, street-address, postal-code…')
            ->problema(Gravedad::Medio);
    }

    /** @param list<array<string, mixed>> $campos */
    private function obligatorios(Entorno $e, array $campos): BorradorResultado
    {
        $marcados = array_values(array_filter($campos, static fn (array $c): bool => (bool) $c['obligatorio']));
        $conAsterisco = array_values(array_filter($campos, static fn (array $c): bool => !$c['obligatorio'] && str_contains((string) ($c['etiqueta'] ?? ''), '*')));
        $v = $e->resultado('ACC-09.campos-obligatorios');
        if ($conAsterisco !== []) {
            $lista = array_map(static fn (array $c): string => self::describir($c) . ' etiqueta «' . $c['etiqueta'] . '»', $conAsterisco);
            return $v->valor(count($conAsterisco) . ' campo(s) con asterisco en la etiqueta pero sin required ni aria-required.')
                ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
                ->consecuencia('El asterisco solo se ve: el lector de pantalla no anuncia que el campo es obligatorio.')
                ->correccion('Añadir required (o aria-required="true") a los campos obligatorios y explicar qué significa el asterisco.')
                ->mejorable();
        }
        if ($marcados === []) {
            return $v->noAplicable('Ningún campo se indica como obligatorio.');
        }
        return $v->valor(count($marcados) . ' campo(s) marcados como obligatorios en el código.')
            ->limitacion('Que también se indique de forma visible lo revisa una persona.')
            ->correcto();
    }

    private function billetes(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('ACC-09.formulario-billetes');
        $formularios = array_values(array_filter($e->documento()->formularios, static function (array $f): bool {
            $texto = strtolower(implode(' ', array_map(static fn (array $c): string => ($c['nombre'] ?? '') . ' ' . ($c['id'] ?? '') . ' ' . ($c['etiqueta'] ?? ''), $f['campos'])) . ' ' . ($f['action'] ?? ''));
            return preg_match('/billete|origen|destino|ida|vuelta|trayecto|pasajero|viaje/', $texto) === 1;
        }));
        $esTransporte = $e->contexto->sector === Sector::Transporte;
        if ($formularios === []) {
            return $v->noAplicable($esTransporte ? 'La página no tiene un formulario de búsqueda o compra de billetes.' : 'No es un proyecto de transporte ni hay formulario de billetes.');
        }
        $campos = $this->campos($e, $formularios);
        $sin = array_values(array_filter($campos, static fn (array $c): bool => !self::tieneNombre($c)));
        $v->valor(count($campos) . ' campos en el formulario de billetes; ' . count($sin) . ' sin etiqueta.')
            ->limitacion('El calendario, los mensajes de error y el pago necesitan navegador.');
        if ($sin === []) {
            return $v->correcto();
        }
        $lista = array_map([self::class, 'describir'], $sin);
        return $v->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
            ->consecuencia('Una persona ciega no puede saber qué es cada desplegable (origen, destino, fecha) y no puede comprar el billete sola.')
            ->correccion('Poner un <label for> visible a cada campo del buscador de billetes: «Origen», «Destino», «Fecha de ida».')
            ->problema(Gravedad::Alto);
    }
}
