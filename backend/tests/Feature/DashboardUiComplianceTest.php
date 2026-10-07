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
        $this->assertStringNotContainsString("{{ $driver->name ?? '#'.$driver->id }}", $view);
        $this->assertStringNotContainsString("Assignment' }} #{{ $assignment['id'] }}", $view);
        $this->assertStringNotContainsString("{{ $event['reason_code'] }}", $view);
    }
}
