<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FunctionalScreenJourneyConvergence1129Test extends TestCase
{
    public function test_critical_dashboard_routes_are_registered(): void
    {
        foreach ([
            'admin.b2c.dashboard',
            'admin.b2c.module',
            'admin.b2c.merchant-intelligence.cart',
            'admin.operations.orders.index',
            'admin.operations.orders.dispatch',
            'admin.operations.orders.dispatch.clear',
            'admin.customer-360.show',
            'admin.customer-360.invoices.settle',
            'admin.customer-360.statement.export',
        ] as $route) {
            $this->assertNotNull(Route::getRoutes()->getByName($route), "Missing canonical route: {$route}");
        }
    }

    public function test_merchant_dashboard_connects_recommendation_inventory_and_cart_actions(): void
    {
        $view = file_get_contents(resource_path('views/admin/_merchant-intelligence-dashboard.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-merchant-intelligence', $view);
        $this->assertStringContainsString('id="merchant-recommendations"', $view);
        $this->assertStringContainsString('focus_product', $view);
        $this->assertStringContainsString('id="suggested-purchase-plan"', $view);
        $this->assertStringContainsString("route('admin.b2c.merchant-intelligence.cart')", $view);
        $this->assertStringContainsString('data-suggested-wholesale-plan', $view);
        $this->assertStringContainsString('merchant-why', $view);
    }

    public function test_persisted_purchase_plan_and_cross_platform_runtime_evidence_exist(): void
    {
        $root = dirname(base_path());
        $merchantTest = file_get_contents(base_path('tests/Feature/RetailMerchantDashboardTest.php'));
        $analyticsTest = file_get_contents(base_path('tests/Feature/PlatformAnalyticsRolloutContractTest.php'));
        $customer = file_get_contents($root.'/apps/customer_app/lib/features/b2b/b2b_journey_screen.dart');
        $driver = file_get_contents($root.'/apps/driver_app/lib/navigation.dart');
        $van = file_get_contents($root.'/apps/van_app/lib/features/foundation/van_foundation_screen.dart');

        $this->assertIsString($merchantTest);
        $this->assertIsString($analyticsTest);
        $this->assertIsString($customer);
        $this->assertIsString($driver);
        $this->assertIsString($van);

        $this->assertStringContainsString('test_owner_can_apply_reviewed_plan_idempotently_to_existing_wholesale_cart', $merchantTest);
        $this->assertStringContainsString('test_plan_reprices_at_mutation_time_and_blocks_stale_budget', $merchantTest);
        $this->assertStringContainsString('FoodexBarChart(', $customer);
        $this->assertStringContainsString('FoodexDonutChart(', $customer);
        $this->assertStringContainsString('_DriverWorkloadChart', $driver);
        $this->assertStringContainsString('driver-home-workload-chart', $driver);
        $this->assertStringContainsString('VanScreenId.orders', $van);
        $this->assertStringContainsString('VanScreenId.dashboard', $van);
        $this->assertStringContainsString('driver-home-workload-chart', $analyticsTest);
    }

    public function test_screen_coverage_matrix_has_no_release_blocking_rows(): void
    {
        $matrix = file_get_contents(dirname(base_path()).'/docs/execution/FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md');

        $this->assertIsString($matrix);

        preg_match_all('/^\|\s*(?:merchant|customer|driver|van|dashboard|crossapp)\.[^\n]+\|\s*PASS\s*\|$/m', $matrix, $passed);
        $this->assertGreaterThanOrEqual(18, count($passed[0]), 'Expected all required screen coverage rows to remain PASS.');

        $this->assertSame(
            0,
            preg_match('/^\|[^\n]+\|\s*(?:PARTIAL|FAIL|UNKNOWN|UNOWNED)\s*\|$/m', $matrix),
            'Release-blocking functional screen coverage state found.',
        );
    }
}
