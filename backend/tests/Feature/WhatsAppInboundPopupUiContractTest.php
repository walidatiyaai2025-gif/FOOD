<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WhatsAppInboundPopupUiContractTest extends TestCase
{
    public function test_global_brand_shell_includes_centered_light_red_whatsapp_popup(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $popup = file_get_contents(resource_path('views/admin/_whatsapp-inbound-popup.blade.php'));

        $this->assertIsString($brand);
        $this->assertIsString($popup);
        $this->assertStringContainsString("@include('admin._whatsapp-inbound-popup')", $brand);
        $this->assertStringContainsString('foodex-whatsapp-inbound-popup-runtime', $popup);
        $this->assertStringContainsString('allowsAgentInbox()', $popup);
        $this->assertStringContainsString('position:fixed', $popup);
        $this->assertStringContainsString('place-items:center', $popup);
        $this->assertStringContainsString('background:color-mix(in srgb,var(--foodex-red) 9%,var(--foodex-surface))', $popup);
        $this->assertStringContainsString('var(--foodex-red)', $popup);
        $this->assertStringContainsString("__('whatsapp.inbound_popup.open')", $popup);
        $this->assertStringContainsString('BroadcastChannel', $popup);
        $this->assertStringContainsString("method: 'PATCH'", $popup);
        $this->assertStringContainsString('window.location.assign', $popup);
    }

    public function test_popup_localization_contains_arabic_and_english_contract_copy(): void
    {
        $english = require lang_path('en/whatsapp.php');
        $arabic = require lang_path('ar/whatsapp.php');

        $this->assertSame('New WhatsApp message', $english['inbound_popup']['title']);
        $this->assertSame('Open conversation', $english['inbound_popup']['open']);
        $this->assertSame('رسالة واتساب جديدة', $arabic['inbound_popup']['title']);
        $this->assertSame('فتح المحادثة', $arabic['inbound_popup']['open']);
    }
}
