<?php

declare(strict_types=1);

namespace ProconSEO\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Exportación a PDF (RF-50) con Dompdf, configurado para que el PDF no cargue recursos remotos:
 *
 * - isRemoteEnabled = false y solo el protocolo file:// permitido: aunque el contenido de la web
 *   analizada trajera una <img src="https://…">, Dompdf no se conecta a ningún sitio (y además ese
 *   contenido llega siempre escapado, como texto);
 * - sin PHP ni JavaScript embebido;
 * - chroot en la carpeta de la propia librería: solo puede leer sus fuentes;
 * - fuente DejaVu Sans (incluida en Dompdf) para que salgan bien tildes, ñ y símbolos.
 *
 * El HTML lo genera la plantilla templates/results/pdf.php con todo escapado con e().
 */
final class ExportadorPdf
{
    public static function opciones(): Options
    {
        $dompdf = dirname((string) (new \ReflectionClass(Dompdf::class))->getFileName(), 2);

        return (new Options())
            ->setIsRemoteEnabled(false)
            ->setAllowedProtocols(['file://'])
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setChroot([$dompdf])
            ->setDefaultFont('DejaVu Sans')
            ->setIsHtml5ParserEnabled(true);
    }

    public static function generar(string $html): string
    {
        $pdf = new Dompdf(self::opciones());
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
