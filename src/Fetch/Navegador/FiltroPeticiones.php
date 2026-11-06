<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\ValidadorUrl;

/**
 * Decide qué peticiones del navegador se atienden (SEG-04, SEG-06).
 *
 * El navegador no tiene red propia: cada petición que hace la página llega aquí y, si se
 * permite, la descarga ClienteHttpSeguro con todas sus validaciones. Se permite solo lo
 * necesario para pintar la página y medirla:
 *
 * - el documento analizado (y las redirecciones que siga);
 * - hojas de estilo, scripts y fuentes, de cualquier host público (suelen venir de un CDN);
 * - peticiones de datos (XHR/fetch) de los hosts del análisis, porque las webs hechas con
 *   JavaScript cargan así su contenido.
 *
 * Nunca se permite lo que puede tener efectos: envíos (POST, PUT…), navegar a otra página,
 * WebSocket, beacons ni avisos a terceros. Las imágenes y vídeos tampoco se descargan: no
 * hacen falta para estas mediciones, y el texto que va sobre una imagen se marca como no
 * verificable en vez de suponer su fondo.
 */
final class FiltroPeticiones
{
    public const DOCUMENTO = 'documento';
    public const SUBRECURSO = 'subrecurso';
    public const DATOS = 'datos';

    /** Tamaño máximo por tipo de recurso, en bytes. */
    private const MAX_BYTES = [
        'Stylesheet' => 2_097_152,
        'Script' => 5_242_880,
        'Font' => 1_048_576,
        'XHR' => 2_097_152,
        'Fetch' => 2_097_152,
    ];

    public function __construct(private readonly Ambito $ambitoPagina, private readonly ValidadorUrl $validadorUrl)
    {
    }

    /**
     * @param bool $documentoEsperado Si es la petición de la página analizada (o de una de sus
     *                                redirecciones) en el marco principal
     * @return array{permitir: bool, clase: ?string, maxBytes: int, motivo: string}
     */
    public function decidir(string $metodo, string $tipo, string $url, bool $documentoEsperado): array
    {
        $no = static fn (string $motivo): array => ['permitir' => false, 'clase' => null, 'maxBytes' => 0, 'motivo' => $motivo];

        if (!in_array(strtoupper($metodo), ['GET', 'HEAD'], true)) {
            return $no('Solo se permiten lecturas: los envíos (' . strtoupper($metodo) . ') podrían tener efectos (SEG-06).');
        }
        try {
            $destino = $this->validadorUrl->validar($url);
        } catch (DestinoRechazado $e) {
            return $no('Dirección no permitida: ' . $e->getMessage());
        }

        if ($tipo === 'Document') {
            return $documentoEsperado
                ? ['permitir' => true, 'clase' => self::DOCUMENTO, 'maxBytes' => 0, 'motivo' => 'Página analizada.']
                : $no('Navegación o marco fuera de la página analizada: no se sigue.');
        }
        if (in_array($tipo, ['Stylesheet', 'Script', 'Font'], true)) {
            return ['permitir' => true, 'clase' => self::SUBRECURSO, 'maxBytes' => self::MAX_BYTES[$tipo], 'motivo' => 'Necesario para pintar la página.'];
        }
        if ($tipo === 'XHR' || $tipo === 'Fetch') {
            return $this->ambitoPagina->permite($destino)
                ? ['permitir' => true, 'clase' => self::DATOS, 'maxBytes' => self::MAX_BYTES[$tipo], 'motivo' => 'Datos que carga la propia web.']
                : $no('Petición de datos a otro dominio (analítica, publicidad…): no se hace.');
        }
        if (in_array($tipo, ['Image', 'Media'], true)) {
            return $no('Imágenes y vídeos no se descargan: no hacen falta para estas mediciones.');
        }

        return $no("Tipo de petición «{$tipo}» no necesario: no se hace.");
    }
}
