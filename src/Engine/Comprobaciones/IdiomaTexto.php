<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use RuntimeException;

/**
 * ACC-10.lang-coincide (WCAG 3.1.1): el idioma declarado en <html lang> es el del texto.
 *
 * Sin IA: se cuentan las palabras más frecuentes de cada idioma (artículos, preposiciones,
 * conjunciones). Solo se da una conclusión cuando hay texto suficiente y un idioma gana con
 * claridad; si no, se dice que no se ha podido saber.
 */
final class IdiomaTexto implements Comprobacion
{
    /** Palabras de menos que no bastan para reconocer el idioma. */
    public const MINIMO_PALABRAS = 40;

    /** El idioma ganador necesita al menos este número de palabras frecuentes… */
    public const MINIMO_ACIERTOS = 8;

    /** …y un 50 % más que el segundo, para no confundir idiomas cercanos. */
    public const VENTAJA = 1.5;

    /**
     * Palabras muy frecuentes de cada idioma. Algunas se comparten entre idiomas cercanos
     * («que», «para»); por eso el ganador tiene que sacar ventaja clara al segundo. «de», «la» o
     * «en» no se incluyen: están en demasiados idiomas.
     */
    private const PALABRAS = [
        'es' => ['el', 'los', 'las', 'del', 'y', 'que', 'por', 'con', 'una', 'para', 'su', 'sus', 'como', 'pero', 'más', 'este', 'esta', 'también', 'muy', 'nuestro', 'nuestra', 'nuestros', 'puede', 'hay', 'desde', 'cuando', 'sobre', 'todo', 'todos', 'usted'],
        'en' => ['the', 'and', 'of', 'to', 'is', 'are', 'for', 'with', 'that', 'this', 'you', 'your', 'our', 'we', 'from', 'by', 'it', 'be', 'have', 'has', 'not', 'or', 'can', 'will', 'more', 'about', 'all', 'at', 'an', 'which'],
        'ca' => ['els', 'les', 'amb', 'i', 'per', 'què', 'és', 'són', 'aquest', 'aquesta', 'també', 'molt', 'nostre', 'nostra', 'dels', 'una', 'pel', 'perquè', 'però', 'més', 'tot', 'seu', 'seva', 'quan', 'fins', 'això', 'cap', 'hi', 'ho', 'ens'],
        'gl' => ['o', 'os', 'as', 'do', 'da', 'dos', 'das', 'e', 'que', 'con', 'unha', 'para', 'non', 'máis', 'tamén', 'moi', 'noso', 'nosa', 'polo', 'pola', 'este', 'esta', 'súa', 'seu', 'cando', 'sobre', 'todo', 'hai', 'pode', 'xa', 'dun', 'dunha', 'nunha', 'coa', 'teñen', 'iso', 'agora'],
        'eu' => ['eta', 'da', 'ez', 'bat', 'du', 'dira', 'dute', 'ere', 'baina', 'edo', 'izan', 'gure', 'zure', 'hau', 'hori', 'egin', 'beste', 'oso', 'baino', 'dago', 'daude', 'gabe', 'arte', 'buruz', 'bere', 'hemen', 'nola', 'zer', 'ondoren', 'guztiak'],
        'fr' => ['le', 'les', 'des', 'et', 'est', 'une', 'pour', 'avec', 'dans', 'sur', 'pas', 'vous', 'nous', 'qui', 'sont', 'au', 'aux', 'du', 'ce', 'cette', 'plus', 'votre', 'notre', 'mais', 'ou', 'leur', 'tout', 'être', 'avez', 'très'],
        'pt' => ['o', 'os', 'as', 'do', 'da', 'dos', 'das', 'e', 'que', 'com', 'uma', 'para', 'não', 'mais', 'também', 'muito', 'nosso', 'nossa', 'pelo', 'pela', 'este', 'esta', 'você', 'seu', 'sua', 'quando', 'sobre', 'são', 'há', 'já', 'em', 'um', 'isso', 'tem', 'têm', 'está', 'estão', 'seus', 'suas', 'foi'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'eine', 'für', 'auf', 'den', 'dem', 'sie', 'wir', 'ihr', 'zu', 'von', 'auch', 'sich', 'oder', 'werden', 'bei', 'aus', 'wie', 'nach', 'über', 'noch', 'sind', 'unsere'],
        'it' => ['il', 'gli', 'della', 'delle', 'e', 'che', 'per', 'con', 'una', 'non', 'sono', 'più', 'anche', 'molto', 'nostro', 'nostra', 'questo', 'questa', 'alla', 'dei', 'nel', 'nella', 'ma', 'come', 'tutti', 'essere', 'ha', 'hanno', 'quando', 'sul'],
    ];

    private const NOMBRES = [
        'es' => 'español', 'en' => 'inglés', 'ca' => 'catalán', 'gl' => 'gallego', 'eu' => 'euskera',
        'fr' => 'francés', 'pt' => 'portugués', 'de' => 'alemán', 'it' => 'italiano',
    ];

    public function subcomprobaciones(): array
    {
        return [
            // Sin IA: solo los idiomas de la lista y con texto suficiente.
            'ACC-10.lang-coincide' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'ACC-10.lang-coincide' => $this->coincide($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Idioma del texto, o null si no hay texto suficiente o ningún idioma gana con claridad.
     *
     * @return array{idioma: ?string, palabras: int, aciertos: array<string, int>}
     */
    public static function reconocer(string $texto): array
    {
        preg_match_all('/[\p{L}]+/u', mb_strtolower($texto), $m);
        $palabras = $m[0];
        $frecuencia = array_count_values($palabras);
        $aciertos = [];
        foreach (self::PALABRAS as $idioma => $lista) {
            $aciertos[$idioma] = 0;
            foreach ($lista as $p) {
                $aciertos[$idioma] += $frecuencia[$p] ?? 0;
            }
        }
        arsort($aciertos);
        $total = count($palabras);
        $idiomas = array_keys($aciertos);
        $primero = $aciertos[$idiomas[0]];
        $segundo = $aciertos[$idiomas[1]];
        $claro = $total >= self::MINIMO_PALABRAS && $primero >= self::MINIMO_ACIERTOS && $primero >= self::VENTAJA * (float) $segundo;

        return ['idioma' => $claro ? $idiomas[0] : null, 'palabras' => $total, 'aciertos' => $aciertos];
    }

    private function coincide(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('ACC-10.lang-coincide')
            ->limitacion('Sin IA: se reconoce el idioma por sus palabras más frecuentes, y solo entre ' . implode(', ', self::NOMBRES) . '.');
        $lang = trim((string) $doc->lang);
        if ($lang === '') {
            return $v->noAplicable('No hay atributo lang (ver ACC-10.lang-presente).');
        }
        $declarado = strtolower(explode('-', $lang)[0]);

        $r = self::reconocer($doc->texto);
        if ($r['idioma'] === null) {
            $motivo = $r['palabras'] < self::MINIMO_PALABRAS
                ? 'La página tiene poco texto (' . $r['palabras'] . ' palabras) para reconocer su idioma sin IA.'
                : 'No se ha podido reconocer con seguridad el idioma del texto sin IA (puede mezclar idiomas o usar uno que no está en la lista).';
            return $v->noVerificable($motivo);
        }

        $detectado = $r['idioma'];
        $nombre = self::NOMBRES[$detectado];
        $v->valor('lang="' . $lang . '"; el texto parece ' . $nombre . ' (' . $r['aciertos'][$detectado] . ' palabras frecuentes de ' . $r['palabras'] . ').')
            ->elemento('<html lang="' . $lang . '">');
        if ($declarado === $detectado) {
            return $v->correcto();
        }
        if (!isset(self::NOMBRES[$declarado])) {
            return $v->noVerificable('El idioma declarado («' . $lang . '») no está entre los que se reconocen sin IA.');
        }

        return $v
            ->explicacion('La página dice estar en ' . self::NOMBRES[$declarado] . ', pero el texto está en ' . $nombre . '.')
            ->consecuencia('El lector de pantalla lee el texto con la pronunciación del ' . self::NOMBRES[$declarado] . ': se entiende mal o nada.')
            ->correccion('Poner en <html> el idioma real del texto: lang="' . $detectado . '". Si la web tiene varios idiomas, cada versión con el suyo.')
            ->correccionCms([
                'wordpress' => 'Ajustes → Generales → Idioma del sitio; con WPML o Polylang, revisar el idioma de cada página.',
                'prestashop' => 'Internacional → Localización → Idiomas: el código ISO del idioma de la tienda.',
            ])
            ->problema(Gravedad::Medio);
    }
}
