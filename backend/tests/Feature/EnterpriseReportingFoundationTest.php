<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Reporting\ReportAccessResolver;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseReportingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->configureReport();
    }

    public function test_report_registry_is_configuration_only_and_executes_no_queries(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $report = app(ReportRegistry::class)->require('orders.summary');

        $this->assertSame('enterprise_bi', $report->moduleCode);
        $this->assertSame('orders_rollup_daily', $report->readModel);
        $this->assertSame([], $queries);
    }

    public function test_disabled_bi_short_circuits_before_entitlement_or_scope_queries(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $allowed = app(ReportAccessResolver::class)->allowsView(
            $admin,
            app(ReportRegistry::class)->require('orders.summary'),
            new EntitlementScope(EntitlementScope::STORE, 999999),
        );

        $this->assertFalse($allowed);
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_module_entitlements')
                || str_contains($sql, 'enterprise_store_scopes'),
        ));
    }

    public function test_report_access_requires_store_entitlement_and_capability(): void
    {
        $store = $this->createStore('BI-A');
        $otherStore = $this->createStore('BI-B');
        $this->enableBi();

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );

        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $access = app(ReportAccessResolver::class);

        $this->assertTrue($access->allowsView(
            $admin,
            $report,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
        ));
        $this->assertTrue($access->allowsExport(
            $admin,
            $report,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
        ));
        $this->assertFalse($access->allowsView(
            $admin,
            $report,
            new EntitlementScope(EntitlementScope::STORE, $otherStore->id),
        ));
    }

    public function test_report_query_enforces_allowlisted_filters_date_range_and_page_bounds(): void
    {
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, [
            'filters' => ['status' => 'COMPLETED'],
            'from' => '2026-10-01',
            'to' => '2026-10-15',
            'page' => 2,
            'per_page' => 50,
        ]);

        $this->assertSame([
            'report' => 'orders.summary',
            'read_model' => 'orders_rollup_daily',
            'filters' => ['status' => 'COMPLETED'],
            'from' => '2026-10-01',
            'to' => '2026-10-15',
            'page' => 2,
            'per_page' => 50,
            'drill_down_route' => 'admin.orders.index',
        ], $query->readModelHandoff());

        $this->expectException(ValidationException::class);
        ReportQuery::fromArray($report, [
            'filters' => ['raw_sql' => '1=1'],
        ]);
    }

    public function test_report_query_rejects_unbounded_ranges_and_exports_chunked_handoff(): void
    {
        $report = app(ReportRegistry::class)->require('orders.summary');

        $query = ReportQuery::fromArray($report, [
            'from' => '2026-10-01',
            'to' => '2026-10-15',
        ]);
        $handoff = $query->exportHandoff(new EntitlementScope(EntitlementScope::STORE, 42));

        $this->assertSame(500, $handoff['chunk_size']);
        $this->assertSame('enterprise-report-exports', $handoff['queue']);
        $this->assertSame('store', $handoff['scope_type']);
        $this->assertSame(42, $handoff['scope_id']);

        try {
            ReportQuery::fromArray($report, [
                'from' => '2026-01-01',
                'to' => '2026-03-01',
            ]);
            $this->fail('Expected an over-bounds report range to be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        ReportQuery::fromArray($report, ['per_page' => 101]);
    }

    private function configureReport(): void
    {
        config()->set('enterprise_reporting.reports', [
            'orders.summary' => [
                'module' => 'enterprise_bi',
                'view_permission' => 'reports.view',
                'export_permission' => 'reports.export',
                'read_capability' => 'reports.read',
                'export_capability' => 'reports.export',
                'dimensions' => ['store', 'status', 'date'],
                'measures' => ['orders', 'gross_total'],
                'allowed_filters' => ['status', 'store_id'],
                'read_model' => 'orders_rollup_daily',
                'drill_down_route' => 'admin.orders.index',
                'max_range_days' => 31,
                'max_page_size' => 100,
                'export_chunk_size' => 500,
            ],
        ]);
    }

    private function enableBi(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');

        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('enterprise_bi', ModuleState::ENABLING);
        $manager->transition('enterprise_bi', ModuleState::ENABLED);
    }

    private function userWithGlobalRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function createStore(string $code): Store
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $store = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
