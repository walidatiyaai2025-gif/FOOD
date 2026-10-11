<?php

namespace Tests\Feature;

use Tests\TestCase;

class NewOrderPopupUiContractTest extends TestCase
{
    public function test_global_dashboard_brand_components_mount_new_order_popup_runtime(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $popup = file_get_contents(resource_path('views/admin/_new-order-popup.blade.php'));

        $this->assertIsString($brand);
        $this->assertIsString($popup);
        $this->assertStringContainsString("@include('admin._new-order-popup')", $brand);
        $this->assertStringContainsString('foodex-new-order-popup-runtime', $popup);
        $this->assertStringContainsString('admin.operations.orders.new-order-feed', $popup);
        $this->assertStringContainsString('sessionStorage', $popup);
        $this->assertStringContainsString('visibilitychange', $popup);
        $this->assertStringContainsString('View order', $popup);
        $this->assertStringContainsString('فتح الطلب', $popup);
        $this->assertStringContainsString('queue.shift()', $popup);
    }
}
