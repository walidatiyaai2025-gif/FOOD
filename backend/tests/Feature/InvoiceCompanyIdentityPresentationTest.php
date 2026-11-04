<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoiceCompanyIdentityPresentationTest extends TestCase
{
    public function test_invoice_company_contact_block_is_stacked_beneath_the_logo(): void
    {
        $partial = file_get_contents(resource_path('views/admin/_invoice-company-identity.blade.php'));
        $invoicePage = file_get_contents(resource_path('views/admin/invoice.blade.php'));
        $orderPopup = file_get_contents(resource_path('views/admin/_order-operation-popup.blade.php'));
        $branding = file_get_contents(app_path('Services/DocumentBrandingService.php'));

        $this->assertIsString($partial);
        $this->assertIsString($invoicePage);
        $this->assertIsString($orderPopup);
        $this->assertIsString($branding);

        $this->assertStringContainsString("@include('admin._invoice-company-identity')", $invoicePage);
        $this->assertStringContainsString("@if(in_array(\$kind, ['details', 'invoice'], true))", $orderPopup);
        $this->assertStringContainsString("@include('admin._invoice-company-identity')", $orderPopup);
        $this->assertStringContainsString('data-invoice-company-identity', $partial);
        $this->assertStringContainsString('class="foodex-invoice-company-logo"', $partial);
        $this->assertStringContainsString("['address']", $partial);
        $this->assertStringContainsString("['phone_secondary']", $partial);
        $this->assertStringContainsString("['phone_primary']", $partial);

        $logoAt = strpos($partial, 'class="foodex-invoice-company-logo"');
        $addressAt = strpos($partial, 'class="foodex-invoice-company-address"');
        $mobileAt = strpos($partial, "['phone_secondary']");
        $telephoneAt = strpos($partial, "['phone_primary']");
        $this->assertNotFalse($logoAt);
        $this->assertNotFalse($addressAt);
        $this->assertNotFalse($mobileAt);
        $this->assertNotFalse($telephoneAt);
        $this->assertLessThan($addressAt, $logoAt);
        $this->assertLessThan($telephoneAt, $mobileAt);

        $this->assertStringContainsString("'address' => '30 ش الوفاء - منطقة الاستاد - الغربية - طنطا'", $branding);
        $this->assertStringContainsString("'phone_secondary' => '01202021034'", $branding);
        $this->assertStringContainsString("'phone_primary' => '0403332801'", $branding);
    }

    public function test_invoice_pdf_places_contact_details_beneath_the_logo_and_uses_configured_identity(): void
    {
        $service = file_get_contents(app_path('Services/InvoiceService.php'));
        $partial = file_get_contents(resource_path('views/admin/_invoice-company-identity.blade.php'));

        $this->assertIsString($service);
        $this->assertIsString($partial);

        $this->assertStringContainsString('app(DocumentBrandingService::class)->identity()', $service);
        $this->assertStringContainsString("app(\\App\\Services\\DocumentBrandingService::class)->identity()", $partial);
        $this->assertStringContainsString("'Mobile'", $service);
        $this->assertStringContainsString("'Telephone'", $service);
        $this->assertStringContainsString("'موبايل'", $service);
        $this->assertStringContainsString("'تليفون'", $service);
        $this->assertStringContainsString(".'<tr><td width=\"100%\">'.$logo.'<br>'", $service);
        $this->assertStringContainsString("implode('<br>', \$contactLines)", $service);
        $this->assertStringContainsString('data-order-popup-content="{{ $kind }}"', file_get_contents(resource_path('views/admin/_order-operation-popup.blade.php')));
        $this->assertStringContainsString('.order-popup-content[data-order-popup-content="invoice"] .order-print-header { display: none !important; }', $partial);
    }
}
