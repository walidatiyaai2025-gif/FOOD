<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationCapabilityInventoryTest extends TestCase
{
    private const STATUSES = [
        'NAV_OK',
        'NAV_WEAK',
        'NAV_MISSING',
        'CONTEXT_ONLY_OK',
        'ADMIN_HUB_OK',
        'INTERNAL_EXCEPTION',
        'DEAD_ROUTE',
        'DUPLICATE_DESTINATION',
    ];

    private const NAVIGATION_TYPES = [
        'SIDEBAR',
        'DOMAIN_HUB',
        'PAGE_TAB',
        'CONTEXT_ACTION',
        'ADMIN_SETTINGS',
        'WORKFLOW_STEP',
        'EXPLICIT_EXCEPTION',
    ];

    public function test_navigation_inventory_has_complete_machine_readable_contract(): void
    {
        $ledger = $this->ledger();

        $this->assertSame(1, $ledger['schema_version']);
        $this->assertSame(1989, $ledger['issue']);
        $this->assertNotEmpty($ledger['capabilities']);

        $required = [
            'domain',
            'capability',
            'route',
            'controller_view',
            'role_persona',
            'permission_entitlement',
            'navigation_owner',
            'navigation_type',
            'visible_entry_point',
            'parent_context',
            'ar_label',
            'en_label',
            'desktop_reachable',
            'mobile_reachable',
            'active_highlight_behavior',
            'back_path',
            'status',
            'evidence',
            'remediation_owner',
            'remediation_scope',
        ];

        foreach ($ledger['capabilities'] as $row) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $row, sprintf('Capability [%s] is missing [%s].', $row['id'] ?? 'unknown', $field));
            }

            $this->assertContains($row['status'], self::STATUSES);
            $this->assertContains($row['navigation_type'], self::NAVIGATION_TYPES);
            $this->assertNotSame('', trim((string) $row['domain']));
            $this->assertNotSame('', trim((string) $row['capability']));
            $this->assertNotSame('', trim((string) $row['ar_label']));
            $this->assertNotSame('', trim((string) $row['en_label']));
            $this->assertNotSame('miscellaneous', strtolower(trim((string) $row['domain'])));

            if (in_array($row['status'], ['NAV_MISSING', 'NAV_WEAK'], true)) {
                $this->assertNotEmpty($row['remediation_owner']);
                $this->assertNotEmpty($row['remediation_scope']);
            }
        }
    }

    public function test_exact_named_routes_in_inventory_still_exist(): void
    {
        foreach ($this->ledger()['capabilities'] as $row) {
            foreach ((array) $row['route'] as $routeName) {
                if (! is_string($routeName) || str_contains($routeName, '/')) {
                    continue;
                }

                $this->assertTrue(
                    Route::has($routeName),
                    sprintf('Navigation inventory route [%s] for capability [%s] is stale.', $routeName, $row['id']),
                );
            }
        }
    }

    public function test_recent_restaurant_saas_capabilities_have_real_inbound_navigation(): void
    {
        $rows = collect($this->ledger()['capabilities'])->keyBy('id');

        foreach ([
            'restaurant.control' => 'NAV_OK',
            'restaurant.operations' => 'NAV_OK',
            'restaurant.menu' => 'NAV_OK',
            'restaurant.appearance' => 'NAV_OK',
            'restaurant.qr' => 'NAV_OK',
            'restaurant.analytics' => 'NAV_OK',
            'restaurant.supply' => 'CONTEXT_ONLY_OK',
            'restaurant.integrations' => 'CONTEXT_ONLY_OK',
            'restaurant.growth' => 'CONTEXT_ONLY_OK',
        ] as $id => $status) {
            $this->assertTrue($rows->has($id), 'Missing Restaurant SaaS audit row: '.$id);
            $this->assertSame($status, $rows[$id]['status']);
            $this->assertNotEmpty($rows[$id]['visible_entry_point']);
        }

        $workspace = file_get_contents(resource_path('views/admin/_smart-ordering-workspace-nav.blade.php'));
        foreach ([
            'admin.smart-ordering.index',
            'admin.smart-ordering.operations.index',
            'admin.smart-ordering.menu.index',
            'admin.smart-ordering.appearance.index',
            'admin.smart-ordering.qr.index',
            'admin.smart-ordering.analytics.index',
            'admin.smart-ordering.supply.index',
            'admin.smart-ordering.integrations.index',
            'admin.smart-ordering.growth.index',
        ] as $route) {
            $this->assertStringContainsString($route, $workspace);
        }
    }

    public function test_compatibility_aliases_and_non_screen_endpoints_are_not_counted_as_new_screens(): void
    {
        $ledger = $this->ledger();
        $overrides = collect($ledger['route_level_overrides'])->keyBy('route');

        foreach ([
            'admin.business.index',
            'admin.catalog.categories.index',
            'admin.b2b.orders.canonical',
            'admin.b2c.orders.canonical',
        ] as $route) {
            $this->assertTrue($overrides->has($route), 'Compatibility route is not registered: '.$route);
            $this->assertSame('DUPLICATE_DESTINATION', $overrides[$route]['status']);
            $this->assertNotEmpty($overrides[$route]['remediation_owner']);
            $this->assertNotEmpty($overrides[$route]['canonical']);
        }

        $this->assertNotEmpty($ledger['explicit_exceptions']);
        foreach ($ledger['explicit_exceptions'] as $exception) {
            $this->assertSame('INTERNAL_EXCEPTION', $exception['status']);
            $this->assertNotEmpty($exception['reason']);
        }
    }

    public function test_current_audit_has_no_unowned_nav_missing_or_weak_findings(): void
    {
        $ledger = $this->ledger();

        $open = collect($ledger['capabilities'])
            ->filter(fn (array $row): bool => in_array($row['status'], ['NAV_MISSING', 'NAV_WEAK'], true))
            ->values();

        $this->assertCount(0, $open);
        $this->assertSame([], $ledger['findings']['nav_missing']);
        $this->assertSame([], $ledger['findings']['nav_weak']);
        $this->assertSame([], $ledger['findings']['prioritized_orphans']);
    }

    /** @return array<string,mixed> */
    private function ledger(): array
    {
        $path = base_path('../docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
