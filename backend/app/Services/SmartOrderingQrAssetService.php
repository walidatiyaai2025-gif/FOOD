<?php

namespace App\Services;

use RuntimeException;

final class SmartOrderingQrAssetService
{
    public function svg(string $publicUrl): string
    {
        $this->loadRuntime();

        if (! class_exists(\TCPDF2DBarcode::class)) {
            throw new RuntimeException('QR generation is temporarily unavailable.');
        }

        $barcode = new \TCPDF2DBarcode($publicUrl, 'QRCODE,H');

        return $barcode->getBarcodeSVGcode(5, 5, 'black');
    }

    public function pdf(string $publicUrl, string $label): string
    {
        $this->loadRuntime();

        if (! class_exists(\TCPDF::class)) {
            throw new RuntimeException('PDF generation is temporarily unavailable.');
        }

        $pdf = new \TCPDF('P', 'mm', 'A6', true, 'UTF-8', false);
        $pdf->setPDFVersion('1.4');
        $pdf->SetCreator('FOODEX');
        $pdf->SetAuthor('FOODEX');
        $pdf->SetTitle($label);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetCompression(true);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->MultiCell(0, 8, $label, 0, 'C');
        $pdf->write2DBarcode($publicUrl, 'QRCODE,H', 25, 28, 55, 55);
        $pdf->SetY(88);
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(0, 5, $publicUrl, 0, 'C');

        return $pdf->Output('', 'S');
    }

    private function loadRuntime(): void
    {
        if (class_exists(\TCPDF::class) && class_exists(\TCPDF2DBarcode::class)) {
            return;
        }

        $bundledRuntime = app_path('ThirdParty/tcpdf/tcpdf.php');
        if (is_file($bundledRuntime)) {
            require_once $bundledRuntime;
        }
    }
}
