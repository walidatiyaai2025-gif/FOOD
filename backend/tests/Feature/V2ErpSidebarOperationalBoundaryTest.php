<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Http\Middleware\EnsureErpOperationalReadiness;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2ErpSidebarOperationalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->wholesaleStore();
    }

    public function test_enabled_entitled_sidebar_module_is_not_a_direct_operational_grant(): void
    {
        $actor = $this->superAdmin('v2-erp-uncertified@example.test');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);

        // A green-looking checklist link must not bypass an uncertified module.
        $readiness = $center->viewData('moduleReadinessReports')['procurement'];
        foreach (['suppliers', 'approved_requests', 'issued_orders', 'posted_receipts', 'handed_off_invoices'] as $stepKey) {
            $step = collect($readiness)->firstWhere('key', $stepKey);
            $this->assertIsArray($step);
            $this->assertNull($step['url'], $stepKey);
        }

        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
        $this->post(route('admin.erp.procurement.requests.store'), [])->assertForbidden();

        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_platform_owner_without_an_active_wholesale_store_keeps_read_only_setup_diagnostics(): void
    {
        $this->app['env'] = 'production';
        Store::query()->where('code', 'V2-ERP-BOUNDARY-WHOLESALE')->update(['is_active' => false]);
        $actor = $this->superAdmin('v2-erp-no-b2b-diagnostics@example.test');

        // A visibility-only exception must never create a B2B store, scope,
        // certified module or operational authorization.
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'procurement.suppliers.view', 'B2B'));

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('setup_required', $card['status']);
        $this->assertNull($card['url']);
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_platform_entitlement_without_procurement_role_does_not_reveal_the_module(): void
    {
        $this->app['env'] = 'production';
        $owner = $this->superAdmin('v2-erp-module-owner@example.test');
        $finance = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $finance->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($finance)->get(route('admin.erp.index'));
        $center->assertOk()
            ->assertDontSee('data-erp-key="procurement"', false);
        $this->assertNull(collect($center->viewData('cards'))->firstWhere('key', 'procurement'));
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_global_finance_role_never_gets_privileged_readiness_setup_links(): void
    {
        $this->app['env'] = 'production';
        $owner = $this->superAdmin('v2-erp-bi-readiness-owner@example.test');
        $finance = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $finance->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($finance)->get(route('admin.erp.index'));
        $center->assertOk();
        $card = collect($center->viewData('cards'))->firstWhere('key', 'bi');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);

        $steps = $center->viewData('moduleReadinessReports')['bi'];
        foreach ($steps as $step) {
            $this->assertNull($step['url'], $step['key']);
        }
    }

    public function test_uncertified_module_routes_fail_closed_for_all_erp_domains(): void
    {
        $this->actingAs($this->superAdmin('v2-erp-cross-module@example.test'));

        foreach ([
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.bi',
        ] as $routeName) {
            $this->get(route($routeName))->assertForbidden();
        }

        // A blocked navigation card must not leave a direct-write bypass:
        // denial belongs to the HTTP middleware, before controller validation
        // or any workflow-side mutation can occur.
        foreach ([
            'admin.erp.wms.transfers.store',
            'admin.erp.finance.journals.store',
            'admin.erp.treasury.payments.store',
            'admin.erp.bi.schedules.store',
        ] as $routeName) {
            $this->post(route($routeName), [])->assertForbidden();
        }
    }

    public function test_platform_allow_cannot_override_explicit_wholesale_store_entitlement_deny(): void
    {
        $actor = $this->superAdmin('v2-erp-store-deny@example.test');
        $store = Store::query()->where('code', 'V2-ERP-BOUNDARY-WHOLESALE')->firstOrFail();

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        $manager = app(ModuleEntitlementManager::class);
        $manager->set('procurement', EntitlementScope::platform(), true);
        $manager->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, (int) $store->getKey()),
            false,
        );

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);

        $steps = $center->viewData('moduleReadinessReports')['procurement'];
        foreach (['suppliers', 'approved_requests', 'issued_orders', 'posted_receipts', 'handed_off_invoices'] as $key) {
            $step = collect($steps)->firstWhere('key', $key);
            $this->assertIsArray($step);
            $this->assertNull($step['url'], $key);
        }
    }

    public function test_wholesale_treasury_readiness_never_links_to_cross_channel_retail_master(): void
    {
        $actor = $this->superAdmin('v2-erp-treasury-cross-channel@example.test');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-ERP-BOUNDARY-RETAIL',
            'name' => 'Retail-only treasury master boundary fixture',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        // The legacy Treasury Master route aggregates both B2B and B2C. A
        // Wholesale ERP checklist must never deep-link to that mixed scope.
        $scope = app(OperationalTenantScope::class);
        $this->assertContains((int) $retail->getKey(), $scope->allowedStoreIds($actor, 'finance.view'));
        $this->assertNotContains((int) $retail->getKey(), $scope->allowedStoreIds($actor, 'finance.view', 'B2B'));

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();
        $steps = $center->viewData('treasuryReadinessSteps');
        $this->assertNotEmpty($steps);
        foreach (['cashbox', 'bank_account'] as $key) {
            $step = collect($steps)->firstWhere('key', $key);
            $this->assertIsArray($step);
            $this->assertNull($step['url'], $key);
        }
    }

    public function test_wholesale_bi_kpi_excludes_retail_and_unattributed_platform_failures(): void
    {
        $actor = $this->superAdmin('v2-erp-bi-channel-boundary@example.test');
        $wholesale = Store::query()->where('code', 'V2-ERP-BOUNDARY-WHOLESALE')->firstOrFail();
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-ERP-BI-RETAIL',
            'name' => 'Retail BI boundary fixture',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );

        foreach ([
            ['store', (int) $wholesale->getKey(), 'wholesale'],
            ['store', (int) $retail->getKey(), 'retail'],
            ['platform', null, 'unattributed'],
        ] as [$scopeType, $scopeId, $key]) {
            DB::table('enterprise_report_exports')->insert([
                'report_code' => 'orders.summary',
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'requested_by' => $actor->getKey(),
                'scheduled_report_id' => null,
                'query_payload' => '{}',
                'row_count' => 0,
                'execution_mode' => 'queued_chunked',
                'chunk_size' => 500,
                'next_offset' => 0,
                'processed_rows' => 0,
                'chunks_completed' => 0,
                'status' => 'failed',
                'failure_code' => 'source_unavailable',
                'artifact_metadata' => null,
                'idempotency_key' => hash('sha256', 'v2-erp-bi-channel-'.$key),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $center = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'snapshot']));
        $center->assertOk();
        $card = collect($center->viewData('cards'))->firstWhere('key', 'bi');
        $this->assertIsArray($card);
        $this->assertSame('available', $card['status']);
        $kpi = collect($center->viewData('kpis'))->firstWhere('key', 'failed_reports');
        $this->assertIsArray($kpi);
        $this->assertTrue($kpi['available']);
        $this->assertSame(1, $kpi['value']);
        $this->assertSame(route('admin.erp.bi'), $kpi['url']);

        // Retail-only data and a platform grant must not manufacture an ERP
        // KPI or destination when no authorized Wholesale store remains.
        $wholesale->update(['is_active' => false]);
        $withoutWholesale = $this->actingAs($actor)->get(route('admin.erp.index'));
        $withoutWholesale->assertOk();
        $blockedKpi = collect($withoutWholesale->viewData('kpis'))->firstWhere('key', 'failed_reports');
        $this->assertIsArray($blockedKpi);
        $this->assertFalse($blockedKpi['available']);
        $this->assertNull($blockedKpi['value']);
        $this->assertNull($blockedKpi['url']);
    }

    public function test_every_erp_business_route_is_covered_by_the_server_guard(): void
    {
        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.requests.store',
            'admin.erp.wms.index',
            'admin.erp.wms.transfers.store',
            'admin.erp.finance.index',
            'admin.erp.finance.journals.post',
            'admin.erp.treasury.index',
            'admin.erp.treasury.payments.post',
            'admin.erp.bi',
            'admin.erp.bi.schedules.store',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(
                EnsureErpOperationalReadiness::class,
                $route->gatherMiddleware(),
                $routeName,
            );
        }

        $this->assertNotNull(Route::getRoutes()->getByName('admin.erp.index'));
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 ERP Boundary',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-ERP-BOUNDARY-WHOLESALE',
            'name' => 'V2 ERP Boundary Wholesale',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
