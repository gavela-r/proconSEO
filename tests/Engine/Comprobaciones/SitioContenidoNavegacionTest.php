<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Extraccion\HuellaPagina;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\ResumenPagina;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Tests\Engine\Soporte\CancelacionFalsa;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;
use ProconSEO\Tests\Engine\Soporte\ProgresoFalso;

/**
 * SEO-08.contenido-duplicado y ACC-11.navegacion-coherente (WCAG 3.2.3) en sitio completo.
 */
final class SitioContenidoNavegacionTest extends TestCase
{
    private const BASE = 'https://www.cumple.example';
    private const DUPLICADO = 'SEO-08.contenido-duplicado';
    private const NAVEGACION = 'ACC-11.navegacion-coherente';

    private const TEXTO_A = 'Somos un taller mecánico familiar en León con más de treinta años de experiencia. Reparamos coches de todas las marcas, hacemos la revisión antes de la ITV, cambiamos neumáticos y aceite, y revisamos los frenos y la suspensión. Trabajamos con piezas originales y te damos presupuesto por escrito antes de empezar cualquier trabajo. Puedes pedir cita por teléfono o pasar por el taller de lunes a viernes por la mañana y por la tarde, y el sábado por la mañana. También tenemos coche de sustitución para que no te quedes sin vehículo mientras arreglamos el tuyo.';
    private const TEXTO_B = 'La tienda online vende fruta y verdura ecológica de temporada directamente del productor. Cada semana preparamos cestas con lo que se recoge en la huerta: tomates, pimientos, calabacines, lechugas y fruta fresca. Los pedidos hechos antes del miércoles se entregan el viernes en toda la provincia, con envío gratuito a partir de cuarenta euros. Todos nuestros productos tienen el sello de agricultura ecológica y se cultivan sin pesticidas químicos. Si algo no te llega en buen estado, te lo cambiamos o te devolvemos el dinero sin preguntas, porque queremos que repitas.';

    /** @param list<string> $menu */
    private static function pagina(string $ruta, string $texto, array $menu = [], ?string $canonical = null): ResumenPagina
    {
        $palabras = HuellaPagina::palabras($texto);
        return new ResumenPagina(self::BASE . $ruta, 200, true, ['T ' . $ruta], [], $canonical === null ? null : self::BASE . $canonical, [],
            huellaTexto: HuellaPagina::huella($palabras), palabrasTexto: count($palabras),
            menu: array_map(static fn (string $r): string => self::BASE . $r, $menu));
    }

    /** @param list<ResumenPagina> $resumenes */
    private static function resultado(array $resumenes, string $id): BorradorResultado
    {
        $s = Motor::v1()->consolidarSitio(Fixtures::contexto('cumple', Alcance::SitioCompleto), $resumenes, new ProgresoFalso(), CancelacionFalsa::nunca());
        foreach ($s->resultados as $r) {
            if ($r->idComprobacion === $id) {
                return $r;
            }
        }
        self::fail("Sin resultado para $id");
    }

    public function testPaginasConTextoDistintoCumplen(): void
    {
        $r = self::resultado([self::pagina('/taller', self::TEXTO_A), self::pagina('/fruta', self::TEXTO_B)], self::DUPLICADO);

        self::assertSame('correcto', $r->estado->value);
        self::assertNotNull($r->limitacion);
    }

    public function testDosPaginasConCasiElMismoTextoSonMejorables(): void
    {
        $leon = self::TEXTO_A;
        $astorga = str_replace('en León', 'en Astorga', self::TEXTO_A);
        $r = self::resultado([self::pagina('/taller-leon', $leon), self::pagina('/taller-astorga', $astorga), self::pagina('/fruta', self::TEXTO_B)], self::DUPLICADO);

        self::assertSame('mejorable', $r->estado->value);
        self::assertStringContainsString('1 grupo(s)', (string) $r->valorObservado);
        self::assertNotEmpty($r->codigosEvidencia);
    }

    public function testUnaCopiaQueDeclaraCanonicalNoCuenta(): void
    {
        $r = self::resultado([self::pagina('/a', self::TEXTO_A), self::pagina('/a-copia', self::TEXTO_A, canonical: '/a'), self::pagina('/b', self::TEXTO_B)], self::DUPLICADO);

        self::assertSame('correcto', $r->estado->value);
    }

    public function testLasPaginasCasiVaciasNoSeComparan(): void
    {
        $r = self::resultado([self::pagina('/a', 'Contacto. Llámenos.'), self::pagina('/b', 'Contacto. Llámenos.')], self::DUPLICADO);

        self::assertSame('no_verificable', $r->estado->value);
    }

    public function testElMenuEnElMismoOrdenCumple(): void
    {
        $menu = ['/', '/servicios', '/contacto'];
        $r = self::resultado([
            self::pagina('/', self::TEXTO_A, $menu),
            self::pagina('/servicios', self::TEXTO_B, $menu),
            // Añadir una opción en una sección no es un fallo.
            self::pagina('/contacto', self::TEXTO_B, ['/', '/servicios', '/servicios/itv', '/contacto']),
        ], self::NAVEGACION);

        self::assertSame('correcto', $r->estado->value);
        self::assertSame('3.2.3', $r->wcagCriterio);
    }

    public function testElMenuEnOtroOrdenEsUnProblemaMedio(): void
    {
        $menu = ['/', '/servicios', '/contacto'];
        $r = self::resultado([
            self::pagina('/', self::TEXTO_A, $menu),
            self::pagina('/servicios', self::TEXTO_B, $menu),
            self::pagina('/contacto', self::TEXTO_B, ['/contacto', '/', '/servicios']),
        ], self::NAVEGACION);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertSame(self::BASE . '/contacto', $r->elemento);
    }

    public function testSinMenuEnVariasPaginasNoSePuedeComparar(): void
    {
        $r = self::resultado([self::pagina('/', self::TEXTO_A, ['/', '/a']), self::pagina('/a', self::TEXTO_B)], self::NAVEGACION);

        self::assertSame('no_verificable', $r->estado->value);
    }

    public function testConUnaSolaPaginaNoSeValoraEnCadaPagina(): void
    {
        $e = Ejecucion::de('cumple');

        self::assertSame('no_verificable', $e->estado(self::DUPLICADO));
        self::assertSame('no_verificable', $e->estado(self::NAVEGACION));
    }

    public function testLaHuellaEstimaElTextoEnComun(): void
    {
        $a = HuellaPagina::huella(HuellaPagina::palabras(self::TEXTO_A));
        $b = HuellaPagina::huella(HuellaPagina::palabras(self::TEXTO_B));

        self::assertCount(HuellaPagina::TAMANO, $a);
        self::assertLessThanOrEqual(ResumenPagina::MAXIMO_HUELLA, count($a));
        self::assertSame($a, HuellaPagina::huella(HuellaPagina::palabras(self::TEXTO_A)), 'Siempre la misma huella');
        self::assertSame(1.0, HuellaPagina::similitud($a, $a));
        self::assertLessThan(0.1, HuellaPagina::similitud($a, $b));
        self::assertSame([], HuellaPagina::huella(['dos', 'palabras']));
        self::assertSame(0.0, HuellaPagina::similitud($a, []));
    }

    public function testElTextoPrincipalNoIncluyeMenuNiPie(): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<html><body><header><nav><a href="/">Inicio</a></nav></header><div><p>Texto propio</p><script>x()</script></div><footer>Aviso legal</footer></body></html>');

        self::assertSame('Texto propio', HuellaPagina::textoPrincipal($dom));
    }

    public function testElResumenDeCadaPaginaLlevaHuellaYMenu(): void
    {
        $resumen = Ejecucion::de('cumple')->salida->resumen;

        self::assertNotNull($resumen);
        self::assertGreaterThan(0, $resumen->palabrasTexto);
        self::assertLessThanOrEqual(ResumenPagina::MAXIMO_MENU, count($resumen->menu));
        foreach ($resumen->menu as $url) {
            self::assertStringNotContainsString('#', $url);
        }
    }
}
