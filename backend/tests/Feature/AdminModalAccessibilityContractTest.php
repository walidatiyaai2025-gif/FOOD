<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminModalAccessibilityContractTest extends TestCase
{
    public function test_shared_modal_runtime_names_traps_isolates_and_restores_focus(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('window.FoodexModalA11y = modalA11y;', $brand);
        $this->assertStringContainsString("if (event.key !== 'Tab') return;", $brand);
        $this->assertStringContainsString('sibling.inert = true;', $brand);
        $this->assertStringContainsString('restoreIsolation();', $brand);
        $this->assertStringContainsString('target?.focus();', $brand);
        $this->assertStringContainsString("overlay.setAttribute('aria-labelledby', headingId);", $brand);
        $this->assertStringContainsString("closeButton.setAttribute('aria-label', isArabic ? 'إغلاق' : 'Close');", $brand);
        $this->assertStringContainsString('window.FoodexFeedbackClose?.();', $brand);
        $this->assertStringContainsString('onEscape: close,', $brand);
        $this->assertStringNotContainsString('aria-label="Close">×</button>', $brand);
    }

    public function test_operational_and_order_modals_reuse_the_shared_focus_lifecycle(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $wizard = file_get_contents(resource_path('views/admin/_order-new-wizard.blade.php'));
        $orders = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($brand);
        $this->assertIsString($wizard);
        $this->assertIsString($orders);

        $this->assertStringContainsString('modalSession = modalA11y.activate({', $brand);
        $this->assertStringContainsString('returnFocus: trigger,', $brand);
        $this->assertStringContainsString('modalSession = window.FoodexModalA11y?.activate({', $wizard);
        $this->assertStringContainsString('returnFocus: opener instanceof HTMLElement ? opener : null,', $wizard);
        $this->assertStringContainsString('<dialog class="order-detail-drawer" open aria-modal="true"', $orders);
        $this->assertStringContainsString("__('order_operations.detail.close')", $orders);
        $this->assertStringContainsString('modalSession = window.FoodexModalA11y.activate({', $orders);
        $this->assertStringContainsString('onEscape: dismissDrawer,', $orders);
    }
}
