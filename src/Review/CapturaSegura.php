<?php

declare(strict_types=1);

namespace ProconSEO\Review;

use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Capturas que suben los revisores (SEG-14, especificación 7.1):
 *
 * - solo PNG o JPG, comprobando el tipo real del contenido (no la extensión ni lo que diga el navegador);
 * - con límite de tamaño y de píxeles, para que una imagen pequeña no ocupe gigas al descomprimirse;
 * - recodificadas con GD: se guarda una imagen nueva, sin metadatos ni datos escondidos del original;
 * - guardadas fuera de public/, con un nombre aleatorio. Solo se sirven a través de la aplicación.
 */
final class CapturaSegura
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_LADO = 8000;
    public const MAX_PIXELES = 40_000_000;

    /** Tipo real => [tipo MIME, extensión]. */
    private const TIPOS = [
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
    ];

    /**
     * @param string $directorio carpeta donde se guardan, fuera de public/ (normalmente storage/capturas)
     * @param bool   $soloSubidas exigir que el fichero llegue por una subida HTTP (siempre en la aplicación;
     *                            los tests lo desactivan para usar ficheros de prueba)
     */
    public function __construct(private readonly string $directorio, private readonly bool $soloSubidas = true)
    {
    }

    /**
     * Valida y recodifica un fichero de $_FILES. Devuelve null si no se subió ninguno.
     *
     * @param array<string, mixed>|null $fichero entrada de $_FILES
     * @return array{ruta: string, mime: string, bytes: int}|null ruta relativa a $directorio
     *
     * @throws ErrorDominio con un mensaje para el usuario si el fichero no es válido
     */
    public function guardar(?array $fichero, string $subcarpeta): ?array
    {
        $error = (int) ($fichero['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fichero === null || $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw self::rechazo('La captura es demasiado grande. El máximo es ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        if ($error !== UPLOAD_ERR_OK || is_array($fichero['tmp_name'] ?? null)) {
            throw self::rechazo('No se ha podido recibir la captura. Inténtalo de nuevo.');
        }

        $temporal = (string) ($fichero['tmp_name'] ?? '');
        if ($temporal === '' || !is_file($temporal) || ($this->soloSubidas && !is_uploaded_file($temporal))) {
            throw self::rechazo('No se ha podido recibir la captura. Inténtalo de nuevo.');
        }

        $bytes = (int) filesize($temporal);
        if ($bytes === 0 || $bytes > self::MAX_BYTES) {
            throw self::rechazo('La captura es demasiado grande o está vacía. El máximo es ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        // Tipo real a partir del contenido. getimagesize() lee la cabecera sin descomprimir la imagen.
        $info = @getimagesize($temporal);
        if ($info === false || !isset(self::TIPOS[$info[2]])) {
            throw self::rechazo('La captura tiene que ser una imagen PNG o JPG.');
        }
        [$ancho, $alto, $tipo] = $info;
        if ($ancho < 1 || $alto < 1 || $ancho > self::MAX_LADO || $alto > self::MAX_LADO || $ancho * $alto > self::MAX_PIXELES) {
            throw self::rechazo('La captura es demasiado grande en píxeles. Reduce su tamaño y vuelve a subirla.');
        }

        $imagen = $tipo === IMAGETYPE_PNG ? @imagecreatefrompng($temporal) : @imagecreatefromjpeg($temporal);
        if ($imagen === false) {
            throw self::rechazo('La captura está dañada o no es una imagen PNG o JPG válida.');
        }

        [$mime, $extension] = self::TIPOS[$tipo];
        $carpeta = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($subcarpeta)) ?? '', '-_');
        $relativa = ($carpeta === '' ? '' : $carpeta . '/') . bin2hex(random_bytes(16)) . '.' . $extension;
        $destino = $this->directorio . '/' . $relativa;

        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0750, true) && !is_dir(dirname($destino))) {
            imagedestroy($imagen);
            throw new \RuntimeException('No se puede crear la carpeta de capturas.');
        }

        // Recodificar: se escribe una imagen nueva a partir de los píxeles. Desaparecen metadatos
        // (EXIF con ubicación, comentarios) y cualquier contenido añadido detrás de la imagen.
        if ($tipo === IMAGETYPE_PNG) {
            imagesavealpha($imagen, true);
            $ok = imagepng($imagen, $destino, 6);
        } else {
            $ok = imagejpeg($imagen, $destino, 90);
        }
        imagedestroy($imagen);
        if (!$ok) {
            @unlink($destino);
            throw new \RuntimeException('No se ha podido guardar la captura.');
        }
        @chmod($destino, 0640);

        return ['ruta' => $relativa, 'mime' => $mime, 'bytes' => (int) filesize($destino)];
    }

    /**
     * Ruta absoluta de una captura guardada, o null si la ruta no es válida o sale de la carpeta.
     */
    public function rutaAbsoluta(string $relativa): ?string
    {
        if (preg_match('#^([a-z0-9_-]+/)?[a-f0-9]{32}\.(png|jpg)$#', $relativa) !== 1) {
            return null;
        }
        $base = realpath($this->directorio);
        $ruta = realpath($this->directorio . '/' . $relativa);
        if (!is_string($base) || !is_string($ruta)) {
            return null;
        }

        return str_starts_with($ruta, $base . DIRECTORY_SEPARATOR) ? $ruta : null;
    }

    private static function rechazo(string $mensaje): ErrorDominio
    {
        return new ErrorDominio('captura_no_valida', $mensaje, 422);
    }
}
