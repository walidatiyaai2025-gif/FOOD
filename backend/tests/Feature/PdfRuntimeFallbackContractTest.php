<?php

namespace Tests\Feature;

use Tests\TestCase;

class PdfRuntimeFallbackContractTest extends TestCase
{
    public function test_dashboard_pdf_factory_keeps_bundled_tcpdf_fallback(): void
    {
        $factory = file_get_contents(app_path('Services/PdfDocumentFactory.php'));

        $this->assertStringContainsString('ThirdParty/tcpdf/tcpdf.php', $factory);
        $this->assertStringContainsString('require_once $bundledRuntime;', $factory);
        $this->assertStringContainsString('class_exists(TCPDF::class)', $factory);
    }

    public function test_smart_ordering_qr_assets_keep_bundled_tcpdf_fallback(): void
    {
        $service = file_get_contents(app_path('Services/SmartOrderingQrAssetService.php'));

        $this->assertStringContainsString('ThirdParty/tcpdf/tcpdf.php', $service);
        $this->assertStringContainsString('ThirdParty/tcpdf/tcpdf_barcodes_2d.php', $service);
        $this->assertStringContainsString('require_once $bundledRuntime;', $service);
        $this->assertStringContainsString('require_once $barcodeRuntime;', $service);
        $this->assertStringContainsString('class_exists(\\TCPDF2DBarcode::class)', $service);
        $this->assertStringContainsString('class_exists(\\TCPDF::class)', $service);
        $this->assertStringContainsString("str_contains($svg, '<svg')", $service);
    }
}
