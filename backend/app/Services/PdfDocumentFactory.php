<?php

namespace App\Services;

use RuntimeException;
use TCPDF;

final class PdfDocumentFactory
{
    public function create(
        bool $rtl,
        float $marginLeft = 12,
        float $marginTop = 12,
        float $marginRight = 12,
        int $fontSize = 10,
    ): TCPDF {
        if (! class_exists(TCPDF::class)) {
            $bundledRuntime = app_path('ThirdParty/tcpdf/tcpdf.php');

            if (is_file($bundledRuntime)) {
                require_once $bundledRuntime;
            }
        }

        if (! class_exists(TCPDF::class)) {
            throw new RuntimeException('PDF generation is temporarily unavailable.');
        }

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPDFVersion('1.4');
        $pdf->SetCreator('FOODEX');
        $pdf->SetAuthor('FOODEX');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($marginLeft, $marginTop, $marginRight);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetCompression(true);
        $pdf->setRTL($rtl);
        $pdf->SetFont('dejavusans', '', $fontSize);

        return $pdf;
    }
}
