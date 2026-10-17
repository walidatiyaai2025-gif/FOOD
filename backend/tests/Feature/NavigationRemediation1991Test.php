<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationRemediation1991Test extends TestCase
{
    public function test_non_restaurant_navigation_findings_are_fully_resolved(): void
    {
        $ledger = $this->ledger();

        $this->assertSame([], $ledger['findings']['nav_missing']);
        $this->assertSame([], $ledger['findings']['nav_weak']);
        $this->assertSame([], $ledger['findings']['prioritized_orphans']);
        $this->assertSame([], $ledger['findings']['permission_visibility_mismatches']);
        $this->assertSame([], $ledger['findings']['misleading_active_context']);
        $this->assertSame([], $ledger['findings']['dead_routes']);
        $this->assertSame([], $ledger['findings']['duplicates']);
        $this->assertSame(0, $ledger['coverage_summary']['unresolved_duplicate_destinations']);
        $this->assertSame(4, $ledger['coverage_summary']['resolved_compatibility_aliases']);
    }

    public function test_compatibility_aliases_converge_without_becoming_navigation_authorities(): void
    {
        foreach ([
            'admin.business.index',
            'admin.catalog.categories.index',
            'admin.b2b.orders.canonical',
            'admin.b2c.orders.canonical',
        ] as $route) {
            $this->assertTrue(Route::has($route), 'Compatibility route disappeared without a migration path: '.$route);
        }

        $business = file_get_contents(app_path('Http/Controllers/Admin/BusinessManagementController.php'));
        $catalog = file_get_contents(app_path('Http/Controllers/Admin/CatalogManagementController.php'));
        $orders = file_get_contents(app_path('Http/Controllers/Admin/OrderOperationsController.php'));
        $navigation = file_get_contents(app_path('Support/AdminNavigation.php'));

        $this->assertStringContainsString("return redirect()->route('admin.index')", $business);
        $this->assertStringContainsString("'tab' => 'categories'", $catalog);
        $this->assertStringContainsString("return redirect()->route('admin.catalog.index', \$params)", $catalog);
        $this->assertStringContainsString("\$request->merge(['channel' => 'b2b'])", $orders);
        $this->assertStringContainsString("\$request->merge(['channel' => 'b2c'])", $orders);
        $this->assertStringContainsString('return $this->index($request);', $orders);

        foreach ([
            'business',
            'catalog_categories',
            'b2b_orders_canonical',
            'b2c_orders_canonical',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString("'key' => '".$forbiddenKey."'", $navigation);
        }
    }

    public function test_resolved_aliases_are_explicitly_owned_in_the_navigation_ledger(): void
    {
        $ledger = $this->ledger();
        $resolved = collect($ledger['findings']['resolved_compatibility_aliases'])->keyBy('route');

        foreach ([
            'admin.business.index',
            'admin.catalog.categories.index',
            'admin.b2b.orders.canonical',
            'admin.b2c.orders.canonical',
        ] as $route) {
            $this->assertTrue($resolved->has($route), 'Missing resolved compatibility evidence for '.$route);
            $this->assertSame('RESOLVED_COMPATIBILITY', $resolved[$route]['status']);
            $this->assertNotEmpty($resolved[$route]['canonical']);
            $this->assertNotEmpty($resolved[$route]['resolution']);
        }
    }

    /** @return array<string,mixed> */
    private function ledger(): array
    {
        $path = base_path('../docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
