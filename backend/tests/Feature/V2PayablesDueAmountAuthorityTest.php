<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsPayableService;
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
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P300: payable-due is the posted AP outstanding balance after real
 * settlement allocations, surfaced in the canonical Finance Payables tab.
 */
final class V2PayablesDueAmountAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_due_amount_uses_posted_ap_less_posted_allocations_and_excludes_paid_off_rows(): void
    {
        [$maker, $checker, $store, $context, $checkerContext, $accounts] = $this->fixture('P300-DUE');
        $ap = app(AccountsPayableService::class);

        $open = $this->postedPayable(
            $maker, $checker, $context, $checkerContext, $store, $accounts,
            'OPEN', 'P300-OPEN Supplier', '100.000',
        );
        $settled = $this->postedPayable(
            $maker, $checker, $context, $checkerContext, $store, $accounts,
            'SETTLED', 'P300-SETTLED Supplier', '70.000',
        );

        $this->allocate(
            $ap, $maker, $checker, $context, $checkerContext, $open->id,
            $accounts['cash'], 'OPEN', '40.000',
        );
        $this->allocate(
            $ap, $maker, $checker, $context, $checkerContext, $settled->id,
            $accounts['cash'], 'SETTLED', '70.000',
        );

        // P300 due = net financial obligation as of the business review date;
        // cancelled/draft, fully settled and unposted allocations are not due.
        $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00'));

        $view = $this->actingAs($maker->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'payables',
                'status' => 'posted',
                'supplier_reference' => 'P300-OPEN Supplier',
            ]))
            ->assertOk()
            ->assertSee('data-finance-workbench="true"', false)
            ->assertSee('P300-OPEN Supplier')
            ->assertSee('2027-02-05');

        $view->assertViewHas('data', function (array $data) use ($open): bool {
            $aging = $data['aging'];

            return $aging->count() === 1
                && (int) $aging->first()->id === (int) $open->id
                && (float) $aging->first()->outstanding_amount === 60.0
                && (string) $aging->first()->aging_bucket === '1_30';
        });

        $allDue = $this->actingAs($maker->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))
            ->assertOk();
        $allDue->assertViewHas('data', function (array $data) use ($open): bool {
            return $data['aging']->count() === 1
                && (int) $data['aging']->first()->id === (int) $open->id;
        });
        // Paid-off documents remain in the posted document ledger for audit,
        // but must NOT be reported again as a still-due obligation.
        $allDue->assertSee('P300-SETTLED Supplier');
        $this->assertDatabaseCount('enterprise_ap_allocations', 2);
        $this->assertDatabaseCount('enterprise_finance_journals', 4);
    }

    public function test_payable_due_read_fails_closed_without_capability_or_authorized_store(): void
    {
        [$actor, , $store, $context] = $this->fixture('P300-SCOPE');
        $foreign = $this->store('P300-FOREIGN');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $foreign->id, 'tab' => 'payables',
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))
            ->assertOk();

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            ['finance.foundation.read'],
            $actor,
        );

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($roleless);
        $this->actingAs($roleless)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))
            ->assertForbidden();
    }

    public function test_global_deny_and_unready_module_prevent_due_visibility(): void
    {
        [$actor, , $store] = $this->fixture('P300-DENY');
        $url = route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'payables',
        ]);

        $this->actingAs($actor)->get($url)->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $actor->forceFill(['has_permission_overrides' => false])->save();
        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
    }

    /** @return array{User, User, Store, ResolvedTenantContext, ResolvedTenantContext, array{payable:int,expense:int,cash:int}} */
    private function fixture(string $code): array
    {
        $store = $this->store($code);
        $maker = $this->actor($store, $code.'-MAKER', ['finance.view', 'finance.manage', 'finance.post']);
        $checker = $this->actor($store, $code.'-CHECKER', ['finance.view', 'finance.post']);
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $checkerContext = app(TenantContextResolver::class)->retail($checker, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $maker);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $maker);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read', 'finance.foundation.manage',
                'finance.gl.read', 'finance.gl.manage', 'finance.gl.post',
                'finance.ap.read', 'finance.ap.manage', 'finance.ap.post',
            ],
            $maker,
        );

        $finance = app(FinanceFoundationService::class);
        $payable = $finance->createAccount($maker, $context, [
            'code' => 'P300-2100', 'name' => 'Payables',
            'type' => 'liability', 'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $expense = $finance->createAccount($maker, $context, [
            'code' => 'P300-5100', 'name' => 'Expense',
            'type' => 'expense', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $cash = $finance->createAccount($maker, $context, [
            'code' => 'P300-1100', 'name' => 'Cash',
            'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $context, [
            'code' => 'P300FY2027', 'name' => 'FY2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $context, $year->id, [
            'sequence' => 1, 'code' => 'P01', 'name' => 'January 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
        ]);

        return [$maker, $checker, $store, $context, $checkerContext, [
            'payable' => $payable->id, 'expense' => $expense->id, 'cash' => $cash->id,
        ]];
    }

    private function postedPayable(
        User $maker,
        User $checker,
        ResolvedTenantContext $context,
        ResolvedTenantContext $checkerContext,
        Store $store,
        array $accounts,
        string $suffix,
        string $supplier,
        string $amount,
    ): \App\Models\EnterpriseAccountsPayableDocument {
        $ap = app(AccountsPayableService::class);
        $document = $ap->createDraft($maker, $context, [
            'idempotency_key' => 'P300-DOC-'.$suffix,
            'store_id' => $store->id,
            'supplier_reference' => $supplier,
            'supplier_name' => $supplier,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => $amount,
            'currency_code' => 'KWD',
            'payable_account_id' => $accounts['payable'],
            'offset_account_id' => $accounts['expense'],
        ]);

        return $ap->post($checker, $checkerContext, $document->id);
    }

    private function allocate(
        AccountsPayableService $ap,
        User $maker,
        User $checker,
        ResolvedTenantContext $context,
        ResolvedTenantContext $checkerContext,
        int $documentId,
        int $cashId,
        string $suffix,
        string $amount,
    ): void {
        $allocation = $ap->createAllocation($maker, $context, $documentId, [
            'idempotency_key' => 'P300-SETTLEMENT-'.$suffix,
            'allocation_date' => '2027-01-10',
            'amount' => $amount,
            'settlement_account_id' => $cashId,
            'reference' => 'P300-SETTLEMENT-'.$suffix,
        ]);
        $ap->postAllocation($checker, $checkerContext, $allocation->id);
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @param list<string> $permissions */
    private function actor(Store $store, string $code, array $permissions): User
    {
        $role = Role::query()->create([
            'code' => str_replace('-', '_', $code),
            'name' => $code,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        foreach ($permissions as $permissionCode) {
            $role->permissions()->syncWithoutDetaching([
                Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
            ]);
        }
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
