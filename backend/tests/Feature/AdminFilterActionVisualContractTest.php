<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminFilterActionVisualContractTest extends TestCase
{
    public function test_dashboard_filter_actions_use_canonical_green_style(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('.foodex-filter-action{', $brand);
        $this->assertStringContainsString('background:var(--foodex-green)!important', $brand);
        $this->assertStringContainsString('.foodex-filter-action:hover', $brand);

        foreach ([
            'lookup-management.blade.php',
            'reports.blade.php',
            'security.blade.php',
            'notification-campaigns.blade.php',
            'notifications.blade.php',
            'translations.blade.php',
            'system-inspector.blade.php',
            'retail-stores.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/admin/'.$view));

            $this->assertIsString($contents);
            $this->assertStringContainsString(
                'foodex-filter-action',
                $contents,
                'Filter/search action is not using the canonical green style in '.$view,
            );
        }
    }
}
