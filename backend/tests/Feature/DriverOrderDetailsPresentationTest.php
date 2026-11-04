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

        $this->assertIsString($driverView);
        $this->assertIsString($orderPopup);
        $this->assertIsString($driverOrders);

        $this->assertStringContainsString('data-driver-order-detail', $driverOrders);
        $this->assertStringContainsString('data-driver-order-details-dialog', $driverView);
        $this->assertStringContainsString('data-driver-order-details-body', $driverView);
        $this->assertStringContainsString('data-driver-order-print-style', $driverView);
        $this->assertStringContainsString('.order-popup-summary', $driverView);
        $this->assertStringContainsString('.order-popup-facts', $driverView);
        $this->assertStringContainsString('.order-popup-table', $driverView);
        $this->assertStringContainsString('data-order-popup-print', $orderPopup);
        $this->assertStringContainsString("event.target.closest('[data-order-popup-print]')", $driverView);
        $this->assertStringContainsString('window.print();', $driverView);
    }

    public function test_driver_print_style_hides_print_header_on_screen_and_reveals_it_on_paper(): void
    {
        $driverView = file_get_contents(resource_path('views/admin/_driver-assignment-management.blade.php'));

        $this->assertIsString($driverView);
        $this->assertStringContainsString('.order-print-header,', $driverView);
        $this->assertStringContainsString('.order-print-footer { display: none; }', $driverView);
        $this->assertStringContainsString('@media print {', $driverView);
        $this->assertStringContainsString('.order-print-header {', $driverView);
        $this->assertStringContainsString('display: grid !important;', $driverView);
        $this->assertStringContainsString('.order-print-footer {', $driverView);
        $this->assertStringContainsString('display: flex !important;', $driverView);
        $this->assertStringContainsString('data-order-print-time', $driverView);
        $this->assertStringContainsString("timeZone: 'Asia/Kuwait'", $driverView);
        $this->assertStringContainsString('data-no-print', $driverView);
    }
}
