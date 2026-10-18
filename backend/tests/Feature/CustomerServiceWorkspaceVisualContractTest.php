<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CustomerServiceWorkspaceVisualContractTest extends TestCase
{
    public function test_customer_service_workspace_uses_compact_single_row_kpis_with_icons_and_counters(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-service-workspace.blade.php'));

        $this->assertIsString($view);
        $this->assertStringNotContainsString('class="foodex-page-toolbar"', $view);
        $this->assertStringContainsString('grid-auto-flow:column', $view);
        $this->assertStringContainsString('grid-auto-columns:minmax(165px,1fr)', $view);
        $this->assertStringContainsString('class="cs-kpi-icon"', $view);
        $this->assertStringContainsString("@include('admin._premium-icon',['name'=>\$presentation['icon']])", $view);
        $this->assertStringContainsString('data-tone="{{ $presentation[\'tone\'] }}"', $view);
        $this->assertStringContainsString('data-cs-counter', $view);
        $this->assertStringContainsString('data-count-target', $view);
        $this->assertStringContainsString("prefers-reduced-motion: reduce", $view);
        $this->assertStringContainsString('window.requestAnimationFrame(tick)', $view);

        foreach ([
            'active_customers',
            'new_customers',
            'repeat_customers',
            'open_cases',
            'overdue_sla',
            'high_risk_customers',
            'unresolved_conversations',
            'recent_complaints',
        ] as $metricKey) {
            $this->assertStringContainsString("'{$metricKey}' => ['icon' =>", $view);
        }
    }
}
