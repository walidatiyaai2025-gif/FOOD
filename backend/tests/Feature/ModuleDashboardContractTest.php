<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModuleDashboardContractTest extends TestCase
{
    public function test_shared_module_dashboard_primitives_and_partials_are_registered(): void
    {
        $css = file_get_contents(public_path('assets/admin/foodex-visualization.css'));
        $kpi = file_get_contents(resource_path('views/admin/_module-dashboard-kpi.blade.php'));
        $filters = file_get_contents(resource_path('views/admin/_module-dashboard-filters.blade.php'));
        $state = file_get_contents(resource_path('views/admin/_module-dashboard-state.blade.php'));
        $registry = json_decode(file_get_contents(base_path('../.ai/uiux/component-registry.json')), true, flags: JSON_THROW_ON_ERROR);
        $archetypes = json_decode(file_get_contents(base_path('../.ai/uiux/page-archetypes.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ([
            '.foodex-module-overview',
            '.foodex-module-toolbar',
            '.foodex-module-date-range',
            '.foodex-module-kpis',
            '.foodex-module-kpi',
            '.foodex-module-chart',
            '.foodex-module-chart-summary',
            '.foodex-module-state',
        ] as $primitive) {
            $this->assertStringContainsString($primitive, $css);
        }

        $this->assertStringContainsString('.foodex-module-date-range{grid-template-columns:minmax(0,1fr);width:100%;max-width:100%}', $css);
        $this->assertStringContainsString('.foodex-module-date-range .foodex-filter-action{width:100%}', $css);

        $this->assertStringContainsString('data-foodex-module-kpi', $kpi);
        $this->assertStringContainsString("['up', 'down', 'neutral']", $kpi);
        $this->assertStringContainsString('drilldown_url', $kpi);
        $this->assertStringContainsString('data-foodex-module-filters', $filters);
        $this->assertStringContainsString('scope_options', $filters);
        $this->assertStringContainsString('data-foodex-module-export', $filters);
        $this->assertStringContainsString('data-foodex-module-state', $state);
        $this->assertStringContainsString("'loading', 'empty', 'error', 'stale', 'permission', 'disabled', 'current'", $state);

        $registered = data_get($registry, 'surfaces.dashboard.primitives.module_dashboard', []);
        $this->assertContains('foodex-module-kpi', $registered);
        $this->assertContains('foodex-module-date-range', $registered);
        $this->assertContains('foodex-module-state', $registered);

        $preferred = data_get($archetypes, 'archetypes.dashboard-kpi.preferred_primitives', []);
        $this->assertContains('foodex-module-kpi', $preferred);
        $this->assertContains('foodex-module-chart', $preferred);
    }

    public function test_module_dashboard_localization_keys_have_ar_en_parity(): void
    {
        $en = require lang_path('en/module_dashboard.php');
        $ar = require lang_path('ar/module_dashboard.php');

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

        $this->assertSame($flatten($en), $flatten($ar));
    }

    public function test_existing_b2b_dashboard_proves_shared_contract_on_a_real_runtime_surface(): void
    {
        $view = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));

        $this->assertStringContainsString('foodex-module-overview', $view);
        $this->assertStringContainsString('foodex-module-kpis', $view);
        $this->assertStringContainsString('foodex-module-kpi', $view);
        $this->assertStringContainsString('foodex-module-date-range', $view);
        $this->assertStringContainsString('foodex-module-chart', $view);
        $this->assertStringContainsString('data-foodex-module-overview', $view);
        $this->assertStringContainsString('data-foodex-module-chart', $view);
    }

    public function test_visualization_contract_covers_scope_drilldown_states_export_and_accessibility(): void
    {
        $contract = file_get_contents(base_path('../docs/design-reference/FOODEX_VISUALIZATION_CONTRACT.md'));

        foreach ([
            'Shared Module Overview contract',
            'server-authoritative',
            'drill-down',
            'permission',
            'stale',
            'large export',
            '320 / 360 / 390 / 412 / 430 / 768 / 1024 / 1280+',
            'table summary',
        ] as $required) {
            $this->assertStringContainsString($required, $contract);
        }
    }
}
