<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2612 / P282: fiscal periods reuse the real Finance workbench.
 * Navigation and form visibility never replace server-side authorization.
 */
final class V2FiscalPeriodsRouteAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_periods_tab_is_localized_and_read_only_operator_cannot_mutate_it(): void
    {
        [$actor, $store, $context] = $this->operator(
            ['finance.view'],
            'V2-2612-VIEW',
            'en',
        );
        $this->enableFinance($actor, $context);

        $this->assertTrue(Route::has('admin.erp.finance.index'));
        $this->assertTrue(Route::has('admin.erp.finance.fiscal-periods.store'));
        $this->assertTrue(Route::has('admin.erp.finance.fiscal-periods.state'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'periods',
                ]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.periods.title'))
                ->assertDontSee('name="sequence"', false);

            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.finance.fiscal-years.store'), [
                    'store_id' => $store->id,
                    'code' => 'FY2612DENIED',
                    'name' => 'Denied Fiscal Year',
                    'starts_on' => '2035-01-01',
                    'ends_on' => '2035-12-31',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_fiscal_years', ['code' => 'FY2612DENIED']);
    }

    public function test_authorized_period_create_close_and_reopen_are_real_http_and_audited(): void
    {
        [$actor, $store, $context] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2612-MANAGE',
            'en',
        );
        $this->enableFinance($actor, $context, true);

        $this->actingAs($actor)->post(route('admin.erp.finance.fiscal-years.store'), [
            'store_id' => $store->id,
            'code' => 'FY2612',
            'name' => 'V2 Fiscal Year',
            'starts_on' => '2035-01-01',
            'ends_on' => '2035-12-31',
        ])->assertRedirect(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'periods',
        ]));

        $yearId = (int) DB::table('enterprise_fiscal_years')
            ->where('code', 'FY2612')
            ->value('id');
        $this->assertGreaterThan(0, $yearId);

        $this->actingAs($actor)->post(route('admin.erp.finance.fiscal-periods.store', [
            'fiscalYear' => $yearId,
        ]), [
            'store_id' => $store->id,
            'code' => 'P2612',
            'name' => 'V2 January',
            'sequence' => 1,
            'starts_on' => '2035-01-01',
            'ends_on' => '2035-01-31',
        ])->assertRedirect(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'periods',
        ]));

        $periodId = (int) DB::table('enterprise_fiscal_periods')
            ->where('code', 'P2612')
            ->value('id');
        $this->assertGreaterThan(0, $periodId);
        $this->assertDatabaseHas('enterprise_fiscal_periods', [
            'id' => $periodId,
            'fiscal_year_id' => $yearId,
            'status' => 'open',
        ]);

        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'periods',
        ]))->assertOk()->assertSee('P2612');

        $this->actingAs($actor)->patch(route('admin.erp.finance.fiscal-periods.state', [
            'fiscalPeriod' => $periodId,
        ]), [
            'store_id' => $store->id,
            'state' => 'closed',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_fiscal_periods', [
            'id' => $periodId,
            'status' => 'closed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.fiscal_period_closed',
            'auditable_id' => $periodId,
        ]);

        $this->actingAs($actor)->patch(route('admin.erp.finance.fiscal-periods.state', [
            'fiscalPeriod' => $periodId,
        ]), [
            'store_id' => $store->id,
            'state' => 'open',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_fiscal_periods', [
            'id' => $periodId,
            'status' => 'open',
        ]);
    }

    public function test_foreign_store_cannot_read_or_transition_another_legal_entity_period(): void
    {
        [$owner, $storeA, $contextA] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2612-OWNER',
            'en',
        );
        $this->enableFinance($owner, $contextA, true);

        $finance = app(FinanceFoundationService::class);
        $year = $finance->createFiscalYear($owner, $contextA, [
            'code' => 'FY2612SCOPE',
            'name' => 'Owner Fiscal Year',
            'starts_on' => '2036-01-01',
            'ends_on' => '2036-12-31',
        ]);
        $period = $finance->createFiscalPeriod($owner, $contextA, $year->id, [
            'code' => 'P2612OWNER',
            'name' => 'Owner Only',
            'sequence' => 1,
            'starts_on' => '2036-01-01',
            'ends_on' => '2036-01-31',
        ]);

        [$outsider, $storeB, $contextB] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2612-OUTSIDER',
            'en',
            true,
        );
        $this->enableFinance($outsider, $contextB, true);

        $this->actingAs($outsider)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeB->id,
                'tab' => 'periods',
            ]))
            ->assertOk()
            ->assertDontSee('P2612OWNER');

        $this->actingAs($outsider)->patch(route('admin.erp.finance.fiscal-periods.state', [
            'fiscalPeriod' => $period->id,
        ]), [
            'store_id' => $storeB->id,
            'state' => 'closed',
        ])->assertNotFound();

        $this->actingAs($owner)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeB->id,
                'tab' => 'periods',
            ]))
            ->assertNotFound();

        $this->actingAs($owner)->patch(route('admin.erp.finance.fiscal-periods.state', [
            'fiscalPeriod' => $period->id,
        ]), [
            'store_id' => $storeB->id,
            'state' => 'closed',
        ])->assertNotFound();

        $this->assertDatabaseHas('enterprise_fiscal_periods', [
            'id' => $period->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.fiscal_period_closed',
            'auditable_id' => $period->id,
        ]);
        $this->assertSame($storeA->id, $contextA->storeId);
    }

    public function test_disabled_finance_module_denies_period_read_and_create_without_writes(): void
    {
        [$actor, $store] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2612-DISABLED',
            'en',
        );

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'periods',
            ]))->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.erp.finance.fiscal-years.store'), [
                'store_id' => $store->id,
                'code' => 'FY2612OFF',
                'name' => 'Must Not Exist',
                'starts_on' => '2037-01-01',
                'ends_on' => '2037-12-31',
            ])->assertForbidden();

        $this->assertDatabaseMissing('enterprise_fiscal_years', ['code' => 'FY2612OFF']);
    }

    /** @param list<string> $permissions
     * @return array{User, Store, ResolvedTenantContext}
     */
    private function operator(
        array $permissions,
        string $storeCode,
        string $locale,
        bool $separateLegalEntity = false,
    ): array {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        if ($separateLegalEntity) {
            // The default hierarchy puts every store in the SAME legal entity.
            // Use a truly independent legal-entity scope for the denial fixture.
            $organization = EnterpriseOrganization::query()->create([
                'code' => 'v2_2612_org_'.$store->id,
                'name' => 'V2 Fiscal Period Isolation Organization',
                'is_active' => true,
            ]);
            $company = EnterpriseCompany::query()->create([
                'organization_id' => $organization->id,
                'code' => 'v2_2612_co_'.$store->id,
                'name' => 'V2 Fiscal Period Isolation Company',
                'is_active' => true,
            ]);
            $legalEntity = EnterpriseLegalEntity::query()->create([
                'company_id' => $company->id,
                'code' => 'v2_2612_legal_'.$store->id,
                'name' => 'V2 Fiscal Period Isolation Legal Entity',
                'is_active' => true,
            ]);
            $businessUnit = EnterpriseBusinessUnit::query()->create([
                'legal_entity_id' => $legalEntity->id,
                'code' => 'v2_2612_unit_'.$store->id,
                'name' => 'V2 Fiscal Period Isolation Unit',
                'is_active' => true,
            ]);
            app(OrganizationHierarchyService::class)->assignStore($store, $businessUnit);
        } else {
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        }

        // The seeded B2C_STORE_ADMIN already has finance.manage.
        // Use a dedicated least-privilege role to prove viewer/manager separation.
        $role = Role::query()->create([
            'code' => 'V2_2612_'.str_replace('-', '_', $storeCode),
            'name' => 'V2 Fiscal Period '.$storeCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create([
            'email' => strtolower($storeCode).'@example.test',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, app(TenantContextResolver::class)->retail($actor, $store->id)];
    }

    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        bool $manage = false,
    ): void {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $manage
                ? ['finance.foundation.read', 'finance.foundation.manage']
                : ['finance.foundation.read'],
            $actor,
        );
    }
}
