<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardUiComplianceTest extends TestCase
{
    public function test_order_management_uses_compact_ellipsis_row_actions(): void
    {
        $view = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-order-row-actions', $view);
        $this->assertStringContainsString('>⋮</summary>', $view);
        $this->assertStringContainsString('View order', $view);
        $this->assertStringNotContainsString('<td><div class="actions">', $view);
        $this->assertStringNotContainsString('store_id={{ $detail[\'store_id\'] }}', $view);
        $this->assertStringNotContainsString('channel={{ $detail[\'channel\'] }}', $view);
        $this->assertStringContainsString('$businessLabel', $view);
        $this->assertStringNotContainsString('$driver->id', $view);
        $this->assertStringNotContainsString('#{{ $assignment[\'id\'] }}', $view);
        $this->assertStringNotContainsString('{{ $event[\'reason_code\'] }}', $view);
    }


    public function test_live_tracking_uses_authorized_store_lookup_instead_of_raw_store_id(): void
    {
        $view = file_get_contents(resource_path('views/admin/_driver-live-map.blade.php'));
        $script = file_get_contents(public_path('assets/admin/driver-live-map.js'));

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertStringContainsString('<select data-live-map="store">', $view);
        $this->assertStringContainsString('data-driver-live-map-stores', $view);
        $this->assertStringNotContainsString("driver_live_tracking.store_id') }}<input data-live-map=\"store\"", $view);
        $this->assertStringContainsString('storeLabel(row)', $script);
        $this->assertStringNotContainsString("i18n.store+' '+(row.store_id", $script);
    }

    public function test_reports_render_business_breakdowns_instead_of_raw_json(): void
    {
        $view = file_get_contents(resource_path('views/admin/reports.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-report-breakdowns', $view);
        $this->assertStringContainsString('data-report-breakdown="{{ $extra }}"', $view);
        $this->assertStringContainsString('$reportLabel', $view);
        $this->assertStringNotContainsString('json_encode($data[$extra]', $view);
        $this->assertStringNotContainsString('<pre style="white-space:pre-wrap;margin:0">', $view);
    }

    public function test_notifications_use_shared_foodex_admin_shell(): void
    {
        $view = file_get_contents(resource_path('views/admin/notifications.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('foodex-admin-layout', $view);
        $this->assertStringContainsString("@include('admin._sidebar'", $view);
        $this->assertStringContainsString('foodex-admin-main', $view);
    }
}
