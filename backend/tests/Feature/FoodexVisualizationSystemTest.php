<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoodexVisualizationSystemTest extends TestCase
{
    public function test_dashboard_shell_loads_shared_visualization_system(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));
        $css = file_get_contents(public_path('assets/admin/foodex-visualization.css'));

        $this->assertIsString($brand);
        $this->assertIsString($css);
        $this->assertStringContainsString('assets/admin/foodex-visualization.css', $brand);

        foreach ([
            '.foodex-viz-line',
            '.foodex-viz-bars',
            '.foodex-viz-donut',
            '.foodex-viz-sparkline',
            '.foodex-viz-progress',
            '.foodex-viz-heatmap',
            '.foodex-viz-legend',
            '.foodex-module-overview',
            '.foodex-module-toolbar',
            '.foodex-module-kpis',
            '.foodex-module-kpi',
            '.foodex-module-chart',
            '.foodex-module-state',
        ] as $primitive) {
            $this->assertStringContainsString($primitive, $css);
        }

        $this->assertStringContainsString('[dir="rtl"] .foodex-viz-bars', $css);
        $this->assertStringContainsString('[dir="rtl"] .foodex-viz-header,[dir="rtl"] .foodex-viz-legend,[dir="rtl"] .foodex-module-toolbar', $css);
        $this->assertStringContainsString('@media(max-width:1023px)', $css);
        $this->assertStringContainsString('@media(max-width:767px)', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
