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
}
