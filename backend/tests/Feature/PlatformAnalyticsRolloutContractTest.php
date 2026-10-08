<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlatformAnalyticsRolloutContractTest extends TestCase
{
    public function test_w8_uses_shared_visualizations_on_authoritative_platform_surfaces(): void
    {
        $b2bDashboard = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $customer = file_get_contents(base_path('../apps/customer_app/lib/features/b2b/b2b_journey_screen.dart'));
        $driver = file_get_contents(base_path('../apps/driver_app/lib/navigation.dart'));
        $vanDashboard = file_get_contents(base_path('../apps/van_app/lib/features/foundation/van_dashboard_page.dart'));
        $vanOrders = file_get_contents(base_path('../apps/van_app/lib/features/orders/van_orders_page.dart'));
        $vanRoutes = file_get_contents(base_path('../apps/van_app/lib/features/visits/van_routes_page.dart'));
        $vanCatalog = file_get_contents(base_path('../apps/van_app/lib/features/orders/van_product_catalog_page.dart'));

        $this->assertStringContainsString('foodex-viz-card', $b2bDashboard);
        $this->assertStringContainsString('foodex-viz-line', $b2bDashboard);
        $this->assertStringContainsString('foodex-viz-donut', $b2bDashboard);

        $this->assertStringContainsString('FoodexBarChart(', $customer);
        $this->assertStringContainsString('FoodexDonutChart(', $customer);
        $this->assertStringContainsString('DriverWorkloadAnalytics', $driver);

        $this->assertStringContainsString('FoodexBarChart(', $vanDashboard);
        $this->assertStringContainsString('FoodexDonutChart(', $vanOrders);
        $this->assertStringContainsString('FoodexDonutChart(', $vanRoutes);
        $this->assertStringContainsString('FoodexDonutChart(', $vanCatalog);
    }

    public function test_w8_visualizations_connect_to_real_operational_contexts(): void
    {
        $b2bDashboard = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $vanDashboard = file_get_contents(base_path('../apps/van_app/lib/features/foundation/van_dashboard_page.dart'));
        $vanFoundation = file_get_contents(base_path('../apps/van_app/lib/features/foundation/van_foundation_screen.dart'));
        $customer = file_get_contents(base_path('../apps/customer_app/lib/features/b2b/b2b_journey_screen.dart'));

        $this->assertStringContainsString('data-platform-analytics-drilldown="b2b-sales"', $b2bDashboard);
        $this->assertStringContainsString('data-platform-analytics-drilldown="b2b-order-distribution"', $b2bDashboard);
        $this->assertStringContainsString("['module'=>'reports']", $b2bDashboard);
        $this->assertStringContainsString("['module'=>'orders']", $b2bDashboard);

        foreach ([
            'onOpenCustomers',
            'onOpenWallet',
            'onOpenReceipts',
            'onOpenRemittance',
        ] as $callback) {
            $this->assertStringContainsString($callback, $vanDashboard);
            $this->assertStringContainsString($callback, $vanFoundation);
        }

        $this->assertStringContainsString("path: '/b2b/orders/\$id'", $customer);
        $this->assertStringContainsString("'channel': 'wholesale'", $customer);
    }
}
