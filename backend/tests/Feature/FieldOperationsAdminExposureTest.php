<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FieldOperationsAdminExposureTest extends TestCase
{
    public function test_business_facing_field_operations_routes_are_exposed_in_admin_dashboard(): void
    {
        foreach ([
            'admin.field-operations.overview',
            'admin.field-operations.fleet',
            'admin.field-operations.fleet.feed',
            'admin.field-operations.vans',
            'admin.field-operations.vans.show',
            'admin.field-operations.vans.store',
            'admin.field-operations.vans.suspend',
            'admin.field-operations.assignments',
            'admin.field-operations.assignments.store',
            'admin.field-operations.customers',
            'admin.field-operations.visits',
            'admin.field-operations.visits.store',
            'admin.field-operations.visits.transition',
            'admin.field-operations.territories',
            'admin.field-operations.geography.store',
            'admin.field-operations.territories.store',
            'admin.field-operations.territories.geometry.store',
            'admin.field-operations.address-quality',
            'admin.field-operations.address-quality.action',
            'admin.field-operations.routing',
            'admin.field-operations.routing.store',
            'admin.field-operations.routing.action',
            'admin.field-operations.finance',
            'admin.field-operations.finance.remittances.review',
        ] as $route) {
            $this->assertTrue(Route::has($route), "Missing Admin Field Operations route [{$route}].");
        }
    }

    public function test_field_operations_admin_reuses_canonical_domain_services(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/FieldOperationsController.php'));

        $this->assertIsString($controller);
        foreach ([
            'VanRegistryService',
            'VanVisitLifecycleService',
            'TerritoryService',
            'AddressQualityService',
            'RoutingPolicyService',
            'FieldOperationsFinanceService',
            'CollectionCustodyService',
            'CommercialFeatureFlags',
        ] as $service) {
            $this->assertStringContainsString($service, $controller);
        }

        $this->assertStringNotContainsString('CustomerVanAssignment', $controller);
        $this->assertStringContainsString("metadata' => [", $controller);
        $this->assertStringContainsString("'van_assignment_id'", $controller);
    }

    public function test_sidebar_contains_one_coherent_field_operations_group_without_duplicate_commercial_links(): void
    {
        $navigation = file_get_contents(app_path('Support/AdminNavigation.php'));

        $this->assertIsString($navigation);
        $this->assertSame(1, substr_count($navigation, "\$this->group('field_operations'"));
        foreach ([
            'field_ops_overview',
            'field_ops_fleet',
            'field_ops_vans',
            'field_ops_assignments',
            'field_ops_customers',
            'field_ops_visits',
            'field_ops_territories',
            'field_ops_address_quality',
            'field_ops_routing',
            'field_ops_finance',
        ] as $key) {
            $this->assertStringContainsString("'{$key}'", $navigation);
        }
        $this->assertStringNotContainsString("'field_ops_commercial_rules'", $navigation);
        $this->assertStringNotContainsString("'field_ops_van_offers'", $navigation);
        $this->assertSame(1, substr_count($navigation, "'commercial_sales_control'"));
        $this->assertSame(1, substr_count($navigation, "'commercial_flash_offers'"));
    }

    public function test_field_operations_surface_reuses_shared_map_finance_and_visual_coverage_contracts(): void
    {
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));
        $map = file_get_contents(resource_path('views/admin/_driver-live-map.blade.php'));
        $mapRuntime = file_get_contents(public_path('assets/admin/driver-live-map.js'));
        $finance = file_get_contents(resource_path('views/admin/_field-operations-finance.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-field-operations-page=', $view);
        $this->assertStringContainsString("@include('admin._driver-live-map'", $view);
        $this->assertStringContainsString("@include('admin._field-operations-finance'", $view);
        $this->assertStringContainsString("@elseif(\$section === 'van-detail')", $view);
        $this->assertStringContainsString('Assignment history', $view);
        $this->assertStringContainsString('Details & history', $view);
        $this->assertStringContainsString('fieldops-coverage-map', $view);
        $this->assertStringContainsString("type:'Polygon'", $view);
        $this->assertStringContainsString("featureFlags['commercial_rules_enabled']", $view);
        $this->assertStringContainsString("featureFlags['van_offers_enabled']", $view);

        $this->assertStringContainsString('data-actor-kind="{{ $trackingActor }}"', $map);
        $this->assertStringContainsString("actorKind === 'van'", $mapRuntime);
        $this->assertStringContainsString("actor_id: actorKind === 'van' ? inputValue('actor-id') : ''", $mapRuntime);

        $this->assertStringContainsString("\$opsRouteName = \$opsRouteName ?? 'admin.b2b.module';", $finance);
        $this->assertStringContainsString('route($opsReviewRouteName', $finance);
    }

    public function test_field_operations_has_english_and_arabic_navigation_labels(): void
    {
        $english = file_get_contents(lang_path('en/admin.php'));
        $arabic = file_get_contents(lang_path('ar/admin.php'));

        $this->assertStringContainsString("'field_operations' => 'Van & Field Operations'", $english);
        $this->assertStringContainsString("'fleet' => 'Live Fleet Map'", $english);
        $this->assertStringContainsString("'field_operations' => 'عمليات الفان والميدان'", $arabic);
        $this->assertStringContainsString("'fleet' => 'خريطة الأسطول الحية'", $arabic);
    }
}
