<?php

namespace Tests\Feature;

use Tests\TestCase;

class RestaurantOperationsDashboardConvergenceTest extends TestCase
{
    public function test_restaurant_landing_composes_existing_capabilities_into_one_overview(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/SmartOrderingController.php'));
        $service = file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantOperationsOverviewService.php'));
        $view = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));

        $this->assertStringContainsString('RestaurantOperationsOverviewService', $controller);
        $this->assertStringContainsString('$overview->snapshot($site, $actor, $overviewFilters)', $controller);

        foreach ([
            'smart_ordering_order_contexts',
            'smart_ordering_reservations',
            'smart_ordering_waitlist_entries',
            'smart_ordering_kitchen_ticket_states',
            'restaurant_time_clock_sessions',
            'smart_ordering_supply_waste_events',
            'smart_ordering_supply_reorder_drafts',
            'restaurant_saas_integrations',
            'restaurant_saas_integration_events',
        ] as $authority) {
            $this->assertStringContainsString($authority, $service);
        }

        foreach ([
            'data-restaurant-operations-overview',
            'foodex-module-overview',
            'foodex-module-kpis',
            'data-restaurant-primary-kpis',
            'data-restaurant-analytics-drilldown',
            'data-foodex-module-filters',
            'data-foodex-module-export',
            'admin.smart-ordering.operations.index',
            'admin.smart-ordering.analytics.index',
            'admin.smart-ordering.analytics.export',
            'admin.smart-ordering.supply.index',
            'admin.smart-ordering.integrations.index',
        ] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
    }

    public function test_restaurant_overview_filters_and_exceptions_are_scope_safe_and_drillable(): void
    {
        $service = file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantOperationsOverviewService.php'));
        $view = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));

        foreach ([
            "'branch' => ['nullable', 'uuid']",
            "'channel' => ['nullable', Rule::in(['dine_in', 'pickup', 'delivery'])]",
        ] as $validation) {
            $this->assertStringContainsString($validation, file_get_contents(app_path('Http/Controllers/Admin/SmartOrderingController.php')));
        }

        $this->assertStringContainsString('->where(\'b.site_id\', $site->getKey())', $service);
        $this->assertStringContainsString('abort_if($requestedBranch !== \'\' && $branch === null, 404)', $service);
        $this->assertStringContainsString("'kitchen_late'", $service);
        $this->assertStringContainsString("'attendance'", $service);
        $this->assertStringContainsString("'reorder'", $service);
        $this->assertStringContainsString("'integrations'", $service);

        $this->assertStringContainsString("['tab'=>'reservations']", $view);
        $this->assertStringContainsString("['tab'=>'waitlist']", $view);
        $this->assertStringContainsString("['tab'=>'kitchen']", $view);
        $this->assertStringContainsString("['tab'=>'workforce']", $view);
    }

    public function test_restaurant_overview_copy_has_arabic_english_key_parity(): void
    {
        $en = require lang_path('en/smart_ordering.php');
        $ar = require lang_path('ar/smart_ordering.php');

        $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    array_push($keys, ...$flatten($value, $path));
                } else {
                    $keys[] = $path;
                }
            }
            sort($keys);

            return $keys;
        };

        $this->assertSame($flatten($en['overview']), $flatten($ar['overview']));
    }

    public function test_restaurant_landing_is_already_in_dashboard_visual_evidence_inventory(): void
    {
        $capture = file_get_contents(base_path('../scripts/capture_web_screenshots.mjs'));

        $this->assertStringContainsString("['smart-ordering', '/admin/smart-ordering']", $capture);
        $this->assertStringContainsString("['smart-ordering-settings', '/admin/smart-ordering/settings']", $capture);
    }
}
