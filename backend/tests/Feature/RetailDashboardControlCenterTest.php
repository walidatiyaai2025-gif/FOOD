<?php

namespace Tests\Feature;

use Tests\TestCase;

class RetailDashboardControlCenterTest extends TestCase
{
    public function test_retail_dashboard_uses_compact_five_tab_control_center_with_assistant_drawer(): void
    {
        $workspace = file_get_contents(resource_path('views/admin/b2c-workspace.blade.php'));
        $controlCenter = file_get_contents(resource_path('views/admin/_retail-dashboard-control-center.blade.php'));

        $this->assertIsString($workspace);
        $this->assertIsString($controlCenter);

        $this->assertStringContainsString("\$dashboardTabs = ['overview', 'sales', 'inventory', 'customers', 'analytics'];", $workspace);
        $this->assertStringContainsString("\$requestedDashboardTab === 'assistant'", $workspace);
        $this->assertStringContainsString("@include('admin._retail-dashboard-control-center')", $workspace);
        $this->assertStringContainsString("@include('admin._merchant-intelligence-dashboard')", $workspace);
        $this->assertStringContainsString('data-dashboard-assistant', $workspace);
        $this->assertStringContainsString('data-dashboard-assistant-open', $workspace);
        $this->assertSame(1, substr_count($workspace, "@include('admin._global-header')"));

        foreach (['overview', 'sales', 'inventory', 'customers', 'analytics'] as $tab) {
            $this->assertStringContainsString('data-dashboard-pane="'.$tab.'"', $controlCenter);
        }

        $this->assertStringContainsString('data-retail-dashboard-control-center', $controlCenter);
        $this->assertStringContainsString('.content[data-retail-control-center]{height:calc(100vh - var(--foodex-header-height));overflow:hidden', $controlCenter);
        $this->assertStringContainsString('grid-template-rows:auto minmax(0,1fr)', $controlCenter);
        $this->assertStringContainsString('data-assistant-anchor="suggested-purchase-plan"', $controlCenter);
    }

    public function test_retail_dashboard_control_center_tabs_are_localized_in_arabic_and_english(): void
    {
        app()->setLocale('ar');
        $this->assertSame('نظرة عامة', __('admin.b2c_dashboard_tabs.overview'));
        $this->assertSame('المبيعات والطلبات', __('admin.b2c_dashboard_tabs.sales'));
        $this->assertSame('المخزون والشراء', __('admin.b2c_dashboard_tabs.inventory'));
        $this->assertSame('العملاء', __('admin.b2c_dashboard_tabs.customers'));
        $this->assertSame('التحليلات', __('admin.b2c_dashboard_tabs.analytics'));

        app()->setLocale('en');
        $this->assertSame('Overview', __('admin.b2c_dashboard_tabs.overview'));
        $this->assertSame('Sales & Orders', __('admin.b2c_dashboard_tabs.sales'));
        $this->assertSame('Inventory & Purchasing', __('admin.b2c_dashboard_tabs.inventory'));
        $this->assertSame('Customers', __('admin.b2c_dashboard_tabs.customers'));
        $this->assertSame('Analytics', __('admin.b2c_dashboard_tabs.analytics'));
    }
}
