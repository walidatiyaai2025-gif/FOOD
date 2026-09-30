<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarOnlyDashboardNavigationTest extends TestCase
{
    public function test_duplicate_workspace_navigation_is_removed_in_favor_of_the_sidebar(): void
    {
        $b2b = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $b2c = file_get_contents(resource_path('views/admin/b2c-workspace.blade.php'));
        $catalog = file_get_contents(resource_path('views/admin/catalog-management.blade.php'));
        $lookups = file_get_contents(resource_path('views/admin/lookup-management.blade.php'));
        $campaigns = file_get_contents(resource_path('views/admin/notification-campaigns.blade.php'));
        $dashboardService = file_get_contents(app_path('Services/B2cDashboardService.php'));

        $this->assertIsString($b2b);
        $this->assertIsString($b2c);
        $this->assertIsString($catalog);
        $this->assertIsString($lookups);
        $this->assertIsString($campaigns);
        $this->assertIsString($dashboardService);

        $this->assertStringNotContainsString('workspace-tabs', $b2b);
        $this->assertStringNotContainsString('Wholesale core modules', $b2b);

        $this->assertStringNotContainsString('Retail core modules', $b2c);
        $this->assertStringNotContainsString("['quick_actions']", $b2c);
        $this->assertStringNotContainsString("'quick_actions' =>", $dashboardService);

        $this->assertStringNotContainsString(
            '<a class="btn" href="{{ route(\'admin.index\') }}',
            $catalog,
        );
        $this->assertStringNotContainsString(
            '<a class="btn" href="{{ route(\'admin.index\') }}',
            $lookups,
        );
        $this->assertStringNotContainsString(
            '<a href="{{ route(\'admin.index\') }}">{{ __(\'admin.overview\') }}</a>',
            $campaigns,
        );

        // Operational actions remain available; only duplicate navigation is removed.
        $this->assertStringContainsString('workspace-inline-form', $b2b);
        $this->assertStringContainsString('module-actions', $b2c);
        $this->assertStringContainsString('foodex-filter-action', $b2c);
        $this->assertStringContainsString("@include('admin._sidebar'", $catalog);
        $this->assertStringContainsString("@include('admin._sidebar'", $lookups);
        $this->assertStringContainsString("@include('admin._sidebar'", $campaigns);
    }
}
