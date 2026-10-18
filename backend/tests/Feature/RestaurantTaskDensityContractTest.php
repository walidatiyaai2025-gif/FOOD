<?php

namespace Tests\Feature;

use Tests\TestCase;

class RestaurantTaskDensityContractTest extends TestCase
{
    public function test_restaurant_workspace_is_task_first_and_customer_orders_are_distinct_from_wholesale_purchases(): void
    {
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        $dashboard = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));
        $menu = file_get_contents(resource_path('views/admin/smart-ordering-menu.blade.php'));
        $operations = file_get_contents(resource_path('views/admin/smart-ordering-operations.blade.php'));

        $this->assertStringContainsString('data-restaurant-task-first-nav', $launcher);
        foreach (['customer_orders', 'menu', 'operations', 'wholesale_purchases', 'settings'] as $task) {
            $this->assertStringContainsString("data-restaurant-primary-task=\"{{ \$task['key'] }}\"", $launcher);
            $this->assertStringContainsString("'".$task."'", $launcher);
        }

        $this->assertStringContainsString('admin.restaurant.wholesale-purchases.index', $launcher);
        $this->assertStringContainsString("operationsUrl('orders')", $launcher);
        $this->assertStringContainsString('data-restaurant-primary-kpis', $dashboard);
        $this->assertStringContainsString('data-restaurant-analytics-drilldown', $dashboard);
        $this->assertStringNotContainsString("['key'=>'aov'", $dashboard);
        $this->assertStringContainsString('data-menu-editor-bounded', $menu);
        $this->assertStringContainsString('tab', $operations);
    }

    public function test_task_first_navigation_has_explicit_responsive_breakpoints_and_bilingual_labels(): void
    {
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        foreach (['1200', '1024', '768', '430', '390', '360'] as $width) {
            $this->assertStringContainsString('max-width:'.$width.'px', $launcher);
        }

        $en = require lang_path('en/smart_ordering.php');
        $ar = require lang_path('ar/smart_ordering.php');

        $this->assertSame('Customer Orders', $en['workspace']['tasks']['customer_orders']);
        $this->assertSame('Wholesale Purchases', $en['workspace']['tasks']['wholesale_purchases']);
        $this->assertSame('طلبات العملاء', $ar['workspace']['tasks']['customer_orders']);
        $this->assertSame('مشتريات الجملة', $ar['workspace']['tasks']['wholesale_purchases']);
    }
}
