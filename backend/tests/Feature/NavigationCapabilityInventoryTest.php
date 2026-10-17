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
            $this->assertNotSame('', trim((string) $row['navigation_owner']));
            $this->assertNotSame('', trim((string) $row['visible_entry_point']));
            $this->assertNotSame('', trim((string) $row['active_highlight_behavior']));
            $this->assertNotSame('', trim((string) $row['back_path']));
            $this->assertNotSame('miscellaneous', strtolower(trim((string) $row['domain'])));
            $this->assertNotSame('DIRECT_URL_ONLY', $row['navigation_type']);
            if ($row['status'] !== 'INTERNAL_EXCEPTION') {
                $this->assertTrue((bool) $row['desktop_reachable']);
                $this->assertTrue((bool) $row['mobile_reachable']);
            }

            if (in_array($row['status'], ['NAV_MISSING', 'NAV_WEAK'], true)) {
                $this->assertNotEmpty($row['remediation_owner']);
                $this->assertNotEmpty($row['remediation_scope']);
            }

            if ($row['navigation_type'] === 'EXPLICIT_EXCEPTION') {
                $this->assertContains($row['status'], ['INTERNAL_EXCEPTION', 'DUPLICATE_DESTINATION', 'DEAD_ROUTE']);
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

    public function test_each_exact_business_route_has_one_navigation_authority(): void
    {
        $owners = [];

        foreach ($this->ledger()['capabilities'] as $row) {
            foreach ((array) $row['route'] as $routeName) {
                if (! is_string($routeName) || str_contains($routeName, '/')) {
                    continue;
                }

                $owners[$routeName][] = $row['id'];
            }
        }

        $duplicates = array_filter(
            $owners,
            static fn (array $capabilityIds): bool => count(array_unique($capabilityIds)) > 1,
        );

        $this->assertSame([], $duplicates, 'A route has more than one navigation authority.');
    }

    public function test_current_business_page_routes_are_registered_or_explicitly_non_navigable(): void
    {
        $this->assertSame(
            [],
            $this->uncoveredBusinessPageRoutes(),
            'A business-facing admin page route has no approved navigation strategy.',
        );
    }

    public function test_navigation_gate_rejects_an_intentionally_orphaned_business_page_fixture(): void
    {
        Route::get('/admin/__navigation-gate-orphan', static fn (): string => 'fixture')
            ->name('admin.__navigation-gate-orphan.index');

        $this->assertContains(
            'admin.__navigation-gate-orphan.index',
            $this->uncoveredBusinessPageRoutes(),
        );
    }

    public function test_navigation_gate_accepts_a_page_tab_fixture_without_requiring_sidebar_placement(): void
    {
        Route::get('/admin/__navigation-gate-tab', static fn (): string => 'fixture')
            ->name('admin.__navigation-gate-tab.index');

        $declared = $this->declaredRouteNames();
        $declared['admin.__navigation-gate-tab.index'] = true;

        $this->assertNotContains(
            'admin.__navigation-gate-tab.index',
            $this->uncoveredBusinessPageRoutes($declared),
        );

        $errors = $this->navigationContractErrors([
            'navigation_type' => 'PAGE_TAB',
            'visible_entry_point' => 'Existing workbench → Fixture tab',
            'ar_label' => 'اختبار',
            'en_label' => 'Fixture',
            'active_highlight_behavior' => 'Parent workbench remains active; tab owns local state',
            'back_path' => 'Parent workbench',
        ]);

        $this->assertSame([], $errors);
    }

    public function test_navigation_gate_rejects_direct_url_only_and_incomplete_strategy_fixtures(): void
    {
        $errors = $this->navigationContractErrors([
            'navigation_type' => 'DIRECT_URL_ONLY',
            'visible_entry_point' => '',
            'ar_label' => '',
            'en_label' => 'Hidden fixture',
            'active_highlight_behavior' => '',
            'back_path' => '',
        ]);

        $this->assertContains('navigation_type', $errors);
        $this->assertContains('visible_entry_point', $errors);
        $this->assertContains('ar_label', $errors);
        $this->assertContains('active_highlight_behavior', $errors);
        $this->assertContains('back_path', $errors);
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
        $this->assertSame([], $ledger['findings']['permission_visibility_mismatches']);
        $this->assertSame([], $ledger['findings']['misleading_active_context']);
        $this->assertSame([], $ledger['findings']['dead_routes']);
    }

    /** @return array<string,true> */
    private function declaredRouteNames(): array
    {
        $declared = [];

        foreach ($this->ledger()['capabilities'] as $row) {
            foreach ((array) $row['route'] as $routeName) {
                if (! is_string($routeName)) {
                    continue;
                }

                if (! str_contains($routeName, '/')) {
                    $declared[$routeName] = true;

                    continue;
                }

                $parts = explode('/', $routeName);
                $first = array_shift($parts);
                $declared[$first] = true;
                $prefix = substr($first, 0, (int) strrpos($first, '.') + 1);

                foreach ($parts as $suffix) {
                    $declared[$prefix.$suffix] = true;
                }
            }
        }

        foreach ($this->ledger()['route_level_overrides'] as $override) {
            if (isset($override['route']) && is_string($override['route'])) {
                $declared[$override['route']] = true;
            }
        }

        foreach ($this->ledger()['explicit_exceptions'] as $exception) {
            foreach ((array) ($exception['routes'] ?? []) as $routeName) {
                if (is_string($routeName)) {
                    $declared[$routeName] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * @param  array<string,true>|null  $declared
     * @return list<string>
     */
    private function uncoveredBusinessPageRoutes(?array $declared = null): array
    {
        $declared ??= $this->declaredRouteNames();
        $uncovered = [];

        foreach (Route::getRoutes() as $route) {
            $routeName = $route->getName();

            if (! is_string($routeName) || ! str_starts_with($routeName, 'admin.')) {
                continue;
            }

            if (array_intersect(['GET', 'HEAD'], $route->methods()) === []) {
                continue;
            }

            if (isset($declared[$routeName])) {
                continue;
            }

            if ($this->isTechnicalRoute($routeName, $route->uri())) {
                continue;
            }

            if (! $this->isBusinessPageCandidate($routeName, $route->uri())) {
                continue;
            }

            $uncovered[] = $routeName;
        }

        sort($uncovered);

        return array_values(array_unique($uncovered));
    }

    private function isBusinessPageCandidate(string $routeName, string $uri): bool
    {
        if (! str_starts_with($uri, 'admin')) {
            return false;
        }

        $segments = explode('.', $routeName);
        $last = end($segments);

        if (in_array($last, ['index', 'dashboard', 'overview', 'center', 'management'], true)) {
            return true;
        }

        return count($segments) <= 3
            && ! in_array($last, ['show', 'create', 'edit'], true);
    }

    private function isTechnicalRoute(string $routeName, string $uri): bool
    {
        $technicalSuffixes = [
            'asset',
            'attachment',
            'callback',
            'data',
            'download',
            'export',
            'feed',
            'lookup',
            'options',
            'poll',
            'preview-data',
            'webhook',
        ];

        $nameSegments = explode('.', $routeName);
        $nameTail = end($nameSegments);
        $uriTail = last(explode('/', trim($uri, '/')));

        return in_array($nameTail, $technicalSuffixes, true)
            || in_array($uriTail, $technicalSuffixes, true);
    }

    /** @param array<string,mixed> $strategy */
    private function navigationContractErrors(array $strategy): array
    {
        $errors = [];

        if (! in_array($strategy['navigation_type'] ?? null, self::NAVIGATION_TYPES, true)) {
            $errors[] = 'navigation_type';
        }

        foreach ([
            'visible_entry_point',
            'ar_label',
            'en_label',
            'active_highlight_behavior',
            'back_path',
        ] as $field) {
            if (! isset($strategy[$field]) || trim((string) $strategy[$field]) === '') {
                $errors[] = $field;
            }
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function ledger(): array
    {
        $path = base_path('../docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
