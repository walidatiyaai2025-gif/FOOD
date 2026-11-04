<?php

namespace Tests\Feature;

use Tests\TestCase;

class DriverOrderDetailsPresentationTest extends TestCase
{
    public function test_driver_order_drilldown_reuses_order_details_and_print_controls(): void
    {
        $driverView = file_get_contents(resource_path('views/admin/_driver-assignment-management.blade.php'));
        $orderPopup = file_get_contents(resource_path('views/admin/_order-operation-popup.blade.php'));
        $driverOrders = file_get_contents(resource_path('views/admin/_driver-orders-popup.blade.php'));
        $ordersView = file_get_contents(resource_path('views/admin/order-operations.blade.php'));
        $printBridge = file_get_contents(resource_path('views/admin/_order-popup-isolated-print.blade.php'));

        $this->assertIsString($driverView);
        $this->assertIsString($orderPopup);
        $this->assertIsString($driverOrders);
        $this->assertIsString($ordersView);
        $this->assertIsString($printBridge);

        $this->assertStringContainsString('data-driver-order-detail', $driverOrders);
        $this->assertStringContainsString('data-driver-order-details-dialog', $driverView);
        $this->assertStringContainsString('data-driver-order-details-body', $driverView);
        $this->assertStringContainsString('data-driver-order-print-style', $driverView);
        $this->assertStringContainsString('.order-popup-summary', $driverView);
        $this->assertStringContainsString('.order-popup-facts', $driverView);
        $this->assertStringContainsString('.order-popup-table', $driverView);
        $this->assertStringContainsString('data-order-popup-print', $orderPopup);
        $this->assertStringContainsString("event.target.closest('[data-order-popup-print]')", $driverView);
        $this->assertStringContainsString("window.FoodexOrderPopupPrint?.(detailsBody);", $driverView);
        $this->assertStringContainsString("window.FoodexOrderPopupPrint?.(body);", $ordersView);
        $this->assertStringContainsString("@include('admin._order-popup-isolated-print')", $driverView);
        $this->assertStringContainsString("@include('admin._order-popup-isolated-print')", $ordersView);
        $this->assertStringNotContainsString('window.print();', $driverView);
    }

    public function test_order_print_is_isolated_from_dashboard_layout_and_brands_the_exact_order(): void
    {
        $driverView = file_get_contents(resource_path('views/admin/_driver-assignment-management.blade.php'));
        $printBridge = file_get_contents(resource_path('views/admin/_order-popup-isolated-print.blade.php'));
        $orderPopup = file_get_contents(resource_path('views/admin/_order-operation-popup.blade.php'));

        $this->assertIsString($driverView);
        $this->assertIsString($printBridge);
        $this->assertIsString($orderPopup);
        $this->assertStringContainsString('data-driver-order-print-style', $driverView);
        $this->assertStringContainsString('const frame = document.createElement(\'iframe\');', $printBridge);
        $this->assertStringContainsString('data-foodex-isolated-print-frame', $printBridge);
        $this->assertStringContainsString('printWindow.print();', $printBridge);
        $this->assertStringContainsString('fragment.cloneNode(true)', $printBridge);
        $this->assertStringContainsString("copy.querySelectorAll('style, script, [data-no-print], .order-popup-toolbar')", $printBridge);
        $this->assertStringContainsString("copy.querySelector('.order-print-header')?.remove()", $printBridge);
        $this->assertStringContainsString("timeZone: 'Asia/Kuwait'", $printBridge);
        $this->assertStringContainsString('@page { size: A4;', $printBridge);
        $this->assertStringContainsString('[data-invoice-company-identity]', $printBridge);
        $this->assertStringContainsString("@if(in_array(\$kind, ['details', 'invoice'], true))", $orderPopup);
        $this->assertStringNotContainsString('@media print {', $driverView);
    }
}
