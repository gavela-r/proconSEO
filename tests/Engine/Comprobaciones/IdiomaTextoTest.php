<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\IdiomaTexto;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * ACC-10.lang-coincide (WCAG 3.1.1): el idioma declarado es el del texto, sin IA.
 */
final class IdiomaTextoTest extends TestCase
{
    private const URL = 'https://www.x.example/';
    private const ID = 'ACC-10.lang-coincide';

    private const TEXTOS = [
        'es' => 'Nuestra empresa ofrece servicios de calidad para las personas que viven en España. Trabajamos todos los días con el objetivo de mejorar la vida de nuestros clientes, y por eso contamos con un equipo muy preparado. También ofrecemos asesoramiento gratuito y usted puede contactar con nosotros cuando quiera. Hay muchas razones para elegirnos: somos una empresa local, conocemos el mercado y no cobramos comisiones ocultas. Ya son más de veinte años al servicio de la comunidad y nuestro compromiso sigue intacto.',
        'en' => 'Our company offers quality services for the people who live in the area. We work every day with the goal of improving the lives of our customers, and that is why we have a very well prepared team. We also offer free advice and you can contact us whenever you want. There are many reasons to choose us: we are a local company, we know the market and we do not charge hidden fees. It has been more than twenty years of service to the community and our commitment is intact.',
        'ca' => 'La nostra empresa ofereix serveis de qualitat per a les persones que viuen a Catalunya. Treballem cada dia amb l\'objectiu de millorar la vida dels nostres clients, i per això comptem amb un equip molt preparat. També oferim assessorament gratuït i pots contactar amb nosaltres quan vulguis. Hi ha moltes raons per escollir-nos: som una empresa local, coneixem el mercat i no cobrem comissions ocultes. Ja són més de vint anys al servei de la comunitat i el nostre compromís continua intacte.',
        'gl' => 'A nosa empresa ofrece servizos de calidade para as persoas que viven en Galicia. Traballamos cada día co obxectivo de mellorar a vida dos nosos clientes, e por iso contamos cun equipo moi preparado. Tamén ofrecemos asesoramento gratuíto e podes contactar connosco cando queiras. Hai moitas razóns para escollernos: somos unha empresa local, coñecemos o mercado e non cobramos comisións ocultas. Xa son máis de vinte anos ao servizo da comunidade e o noso compromiso segue intacto.',
        'pt' => 'A nossa empresa oferece serviços de qualidade para as pessoas que vivem em Portugal. Trabalhamos todos os dias com o objetivo de melhorar a vida dos nossos clientes, e por isso contamos com uma equipa muito preparada. Também oferecemos aconselhamento gratuito e você pode contactar-nos quando quiser. Há muitas razões para nos escolher: somos uma empresa local, conhecemos o mercado e não cobramos comissões ocultas. Já são mais de vinte anos ao serviço da comunidade e o nosso compromisso está intacto.',
        'eu' => 'Gure enpresak kalitatezko zerbitzuak eskaintzen ditu Euskadin bizi diren pertsonentzat. Egunero lan egiten dugu gure bezeroen bizitza hobetzeko helburuarekin, eta horregatik oso prestatuta dagoen talde bat dugu. Doako aholkularitza ere eskaintzen dugu eta nahi duzunean gurekin harremanetan jar zaitezke. Arrazoi asko daude gu aukeratzeko: tokiko enpresa bat gara, merkatua ezagutzen dugu eta ez dugu ezkutuko komisiorik kobratzen. Hogei urte baino gehiago daramatzagu komunitatearen zerbitzura eta gure konpromisoa osorik dago.',
    ];

    private static function ejecutar(string $lang, string $texto): Ejecucion
    {
        $atributo = $lang === '' ? '' : ' lang="' . $lang . '"';
        $html = '<!DOCTYPE html><html' . $atributo . '><head><meta charset="utf-8"><title>T</title></head><body><main><h1>Inicio</h1><p>' . htmlspecialchars($texto) . '</p></main></body></html>';
        $pagina = new RecursoDescargado(self::URL, self::URL, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['www.x.example']));
    }

    public function testElIdiomaDeclaradoCoincideConElTexto(): void
    {
        $r = self::ejecutar('es-ES', self::TEXTOS['es'])->resultado(self::ID);

        self::assertSame('correcto', $r->estado->value);
        self::assertSame('3.1.1', $r->wcagCriterio);
        self::assertStringContainsString('español', (string) $r->valorObservado);
    }

    public function testUnaPaginaEnInglesQueDiceSerEspanolaEsUnProblemaMedio(): void
    {
        $r = self::ejecutar('es', self::TEXTOS['en'])->resultado(self::ID);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertStringContainsString('lang="en"', (string) $r->correccion);
    }

    public function testSinLangNoAplica(): void
    {
        self::assertSame('no_aplicable', self::ejecutar('', self::TEXTOS['es'])->estado(self::ID));
    }

    public function testConPocoTextoNoSePuedeSaber(): void
    {
        $r = self::ejecutar('es', 'Bienvenidos a nuestra web. Llámenos.')->resultado(self::ID);

        self::assertSame('no_verificable', $r->estado->value);
        self::assertStringContainsString('poco texto', (string) $r->limitacion);
    }

    public function testUnIdiomaQueNoSeReconoceSinIaNoSeJuzga(): void
    {
        self::assertSame('no_verificable', self::ejecutar('nl', self::TEXTOS['es'])->estado(self::ID));
    }

    public function testConLaPaginaBloqueadaNoSePuedeComprobar(): void
    {
        self::assertSame('no_verificable', Ejecucion::de('bloqueada-403')->estado(self::ID));
    }

    /** @return iterable<string, array{string}> */
    public static function idiomas(): iterable
    {
        foreach (array_keys(self::TEXTOS) as $idioma) {
            yield $idioma => [$idioma];
        }
    }

    #[DataProvider('idiomas')]
    public function testReconoceLosIdiomasCercanos(string $idioma): void
    {
        self::assertSame($idioma, IdiomaTexto::reconocer(self::TEXTOS[$idioma])['idioma']);
    }

    public function testDosIdiomasMezcladosNoDanConclusion(): void
    {
        self::assertNull(IdiomaTexto::reconocer(self::TEXTOS['es'] . ' ' . self::TEXTOS['en'])['idioma']);
    }
}
