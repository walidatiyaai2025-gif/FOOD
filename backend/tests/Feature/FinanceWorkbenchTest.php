<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
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

final class FinanceWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_workbench_fails_closed_while_finance_module_is_disabled(): void
    {
        [$actor, $store] = $this->operator(['finance.view'], 'ERP-FINANCE-OFF', 'en');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id]))
            ->assertForbidden();
    }

    public function test_authorized_accountant_can_open_every_read_tab_in_arabic_and_english(): void
    {
        [$actor, $store, $context] = $this->operator(['finance.view'], 'ERP-FINANCE-READ', 'en');
        $this->enableFinance($actor, $context);

        $tabs = [
            'overview',
            'accounts',
            'cost_centers',
            'profit_centers',
            'periods',
            'journals',
            'opening_balances',
            'receivables',
            'payables',
            'credit_terms',
            'budgets',
            'tax',
            'allocations',
            'assets',
            'statements',
            'reports',
        ];

        foreach ($tabs as $tab) {
            $this->actingAs($actor)
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('Finance Accountant Workbench')
                ->assertSee('dir="ltr"', false);
        }

        $actor->update(['locale' => 'ar']);

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('مساحة عمل المحاسب')
            ->assertSee('dir="rtl"', false);
    }

    public function test_finance_workbench_rejects_cross_store_context(): void
    {
        [$actor, $store, $context] = $this->operator(['finance.view'], 'ERP-FINANCE-SCOPE', 'en');
        $this->enableFinance($actor, $context);
        $foreignStore = $this->retailStore('ERP-FINANCE-FOREIGN');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $foreignStore->id]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id]))
            ->assertOk();
    }

    public function test_full_finance_workbench_route_surface_is_registered(): void
    {
        foreach ([
            'admin.erp.finance.index',
            'admin.erp.finance.accounts.store',
            'admin.erp.finance.accounts.status',
            'admin.erp.finance.cost-centers.store',
            'admin.erp.finance.cost-centers.status',
            'admin.erp.finance.fiscal-years.store',
            'admin.erp.finance.fiscal-years.state',
            'admin.erp.finance.fiscal-periods.store',
            'admin.erp.finance.fiscal-periods.state',
            'admin.erp.finance.journals.store',
            'admin.erp.finance.journals.approval',
            'admin.erp.finance.journals.post',
            'admin.erp.finance.opening-balances.store',
            'admin.erp.finance.opening-balances.approval',
            'admin.erp.finance.opening-balances.post',
            'admin.erp.finance.opening-balances.cancel',
            'admin.erp.finance.credit-terms.store',
            'admin.erp.finance.credit-terms.status',
            'admin.erp.finance.profit-centers.store',
            'admin.erp.finance.profit-centers.status',
            'admin.erp.finance.accounts.cash-flow',
            'admin.erp.finance.budgets.store',
            'admin.erp.finance.budgets.lines.store',
            'admin.erp.finance.budgets.transition',
            'admin.erp.finance.budgets.revise',
            'admin.erp.finance.tax-codes.store',
            'admin.erp.finance.allocation-rules.store',
            'admin.erp.finance.allocation-rules.apply',
            'admin.erp.finance.consolidation-eliminations.store',
            'admin.erp.finance.asset-categories.store',
            'admin.erp.finance.assets.store',
            'admin.erp.finance.assets.depreciation',
            'admin.erp.finance.assets.disposal',
            'admin.erp.finance.fiscal-periods.close-evaluation',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing Finance UI route: '.$name);
        }
    }

    public function test_finance_completion_reuses_shared_dashboard_and_preserves_localization_parity(): void
    {
        $view = file_get_contents(resource_path('views/admin/finance-workbench.blade.php'));
        $en = require lang_path('en/finance_workbench.php');
        $ar = require lang_path('ar/finance_workbench.php');

        foreach ([
            'data-foodex-module-overview="finance"',
            'foodex-module-kpis',
            'foodex-module-chart',
            "tab==='profit_centers'",
            "tab==='budgets'",
            "tab==='tax'",
            "tab==='allocations'",
            "tab==='assets'",
            "tab==='reports'",
            "route('admin.erp.bi')",
            'admin.erp.finance.accounts.cash-flow',
            'admin.erp.finance.fiscal-periods.close-evaluation',
            'admin.erp.finance.assets.disposal',
            'admin.erp.finance.consolidation-eliminations.store',
        ] as $required) {
            $this->assertStringContainsString($required, $view);
        }

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
        $this->assertStringNotContainsString('name="legal_entity_id"', $view);
        $this->assertStringNotContainsString('name="company_id"', $view);
    }

    /** @param list<string> $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function operator(array $permissions, string $storeCode, string $locale): array
    {
        $store = $this->retailStore($storeCode);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();

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

    private function retailStore(string $code): Store
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

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
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
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.ar.read',
                'finance.ap.read',
                'finance.credit_terms.read',
            ],
            $actor,
        );
    }
}
