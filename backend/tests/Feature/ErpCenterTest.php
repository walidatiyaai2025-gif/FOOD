<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ErpCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_module_is_explicit_and_never_exposes_an_operational_cta(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-owner@example.test');
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('ERP Center')
            ->assertSee('data-erp-key="procurement" data-erp-status="setup_required"', false)
            ->assertSee('Setup required')
            ->assertSee('dir="ltr"', false);
    }

    public function test_local_super_admin_bootstraps_all_erp_modules_and_platform_entitlements(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-modules@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response->assertOk();

        foreach ([
            'procurement' => 'procurement',
            'wms' => 'inventory_wms',
            'finance' => 'finance',
            'treasury' => 'treasury',
            'bi' => 'enterprise_bi',
        ] as $cardKey => $moduleCode) {
            $response->assertSee(
                'data-erp-key="'.$cardKey.'" data-erp-status="available"',
                false,
            );

            $this->assertDatabaseHas('enterprise_modules', [
                'code' => $moduleCode,
                'state' => 'ENABLED',
            ]);

            $this->assertDatabaseHas('enterprise_module_entitlements', [
                'module_code' => $moduleCode,
                'scope_key' => 'platform:0',
                'is_enabled' => true,
            ]);
        }
    }

    public function test_local_super_admin_bootstraps_missing_enabled_erp_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-bootstrap@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
    }

    public function test_local_super_admin_does_not_override_explicit_entitlement_deny(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            false,
            null,
            $actor,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => false,
        ]);
    }

    public function test_local_super_admin_bootstraps_platform_entitlement_when_only_narrower_scope_is_configured(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-narrower-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        EnterpriseModuleEntitlement::query()->create([
            'module_code' => 'procurement',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => 999,
            'scope_key' => 'store:999',
            'is_enabled' => false,
            'capabilities' => null,
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:999',
            'is_enabled' => false,
        ]);
    }

    public function test_production_super_admin_remains_deny_by_default_without_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-production-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);
    }

    public function test_enabled_and_entitled_module_exposes_only_its_canonical_destination(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-enabled@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['module' => 'procurement']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertSee(route('admin.erp.procurement.index'), false);

        $erpGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'erp');
        $this->assertNotNull($erpGroup);

        $procurement = collect($erpGroup['children'])->firstWhere('key', 'erp_procurement');
        $this->assertNotNull($procurement);
        $this->assertSame('admin.erp.procurement.index', $procurement['route']);
        $this->assertSame([], $procurement['params']);
    }

    public function test_erp_navigation_source_uses_canonical_workbench_routes(): void
    {
        $navigation = file_get_contents(app_path('Support/AdminNavigation.php'));
        $center = file_get_contents(app_path('Http/Controllers/Admin/ErpCenterController.php'));

        $this->assertIsString($navigation);
        $this->assertIsString($center);

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.customer-service',
            'admin.erp.bi',
        ] as $route) {
            $this->assertStringContainsString("'".$route."'", $navigation);
            $this->assertStringContainsString("'".$route."'", $center);
        }
    }

    public function test_finance_actor_does_not_receive_procurement_or_wms_actions(): void
    {
        $actor = $this->userWithGlobalRole('FINANCE', 'ar', 'erp-finance@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('مركز ERP')
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('data-erp-key="procurement"', false)
            ->assertDontSee('data-erp-key="wms"', false);
    }

    public function test_erp_center_renders_authoritative_snapshot_drilldowns_and_accessible_analytics(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-snapshot@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-kpis', false)
            ->assertSee('data-erp-kpi="pending_approvals"', false)
            ->assertSee('data-erp-kpi="overdue_work"', false)
            ->assertSee('data-erp-kpi="open_exceptions"', false)
            ->assertSee('data-erp-kpi="failed_reports"', false)
            ->assertSee('data-erp-kpi="module_health"', false)
            ->assertSee('data-erp-chart="workload"', false)
            ->assertSee('data-erp-chart="states"', false)
            ->assertSee('data-erp-my-work', false)
            ->assertSee('data-erp-activity', false)
            ->assertSee('data-erp-work-module="procurement"', false)
            ->assertSee(route('admin.action-center.index'), false)
            ->assertSee(route('admin.erp.procurement.index'), false);
    }

    public function test_failed_report_kpi_is_unavailable_without_bi_and_scope_aware_when_bi_is_operational(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-bi-admin@example.test');

        $unavailable = $this->actingAs($admin)->get(route('admin.erp.index'));
        $unavailableKpi = collect($unavailable->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($unavailableKpi);
        $this->assertFalse($unavailableKpi['available']);
        $this->assertNull($unavailableKpi['value']);
        $this->assertNull($unavailableKpi['url']);

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );

        $actor = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-finance@example.test');
        $other = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-other@example.test');

        foreach ([
            [$actor->id, 'erp-center-own-failure'],
            [$other->id, 'erp-center-other-failure'],
        ] as [$requestedBy, $key]) {
            DB::table('enterprise_report_exports')->insert([
                'report_code' => 'orders.summary',
                'scope_type' => 'platform',
                'scope_id' => null,
                'requested_by' => $requestedBy,
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
                'idempotency_key' => hash('sha256', $key),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));
        $kpi = collect($response->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($kpi);
        $this->assertTrue($kpi['available']);
        $this->assertSame(1, $kpi['value']);
        $this->assertSame(route('admin.erp.bi'), $kpi['url']);
    }

    public function test_erp_center_state_filter_hides_other_module_cards_without_changing_navigation_authority(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-state-filter@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['status' => 'available']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertDontSee('data-erp-key="wms" data-erp-status="disabled"', false);

        $erpGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'erp');
        $this->assertNotNull($erpGroup);
        $this->assertNotNull(collect($erpGroup['children'])->firstWhere('key', 'erp_center'));
    }

    private function userWithGlobalRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
