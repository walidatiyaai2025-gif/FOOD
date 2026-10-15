<?php

namespace App\Services;

use RuntimeException;

final class SmartOrderingQrAssetService
{
    public function svg(string $publicUrl): string
    {
        if (! class_exists(\TCPDF2DBarcode::class)) {
            throw new RuntimeException('TCPDF QR renderer is unavailable.');
        }

        $barcode = new \TCPDF2DBarcode($publicUrl, 'QRCODE,H');

        return $barcode->getBarcodeSVGcode(5, 5, 'black');
    }

    public function pdf(string $publicUrl, string $label): string
    {
        if (! class_exists(\TCPDF::class)) {
            throw new RuntimeException('TCPDF renderer is unavailable.');
        }

        $pdf = new \TCPDF('P', 'mm', 'A6', true, 'UTF-8', false);
        $pdf->SetCreator('FOODEX');
        $pdf->SetAuthor('FOODEX');
        $pdf->SetTitle($label);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(0, 8, $label, 0, 'C');
        $pdf->write2DBarcode($publicUrl, 'QRCODE,H', 25, 28, 55, 55);
        $pdf->SetY(88);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->MultiCell(0, 5, $publicUrl, 0, 'C');

        return $pdf->Output('', 'S');
    }
}
