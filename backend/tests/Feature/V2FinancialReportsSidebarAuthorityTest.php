<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P309: financial reporting reuses the real finance workbench with legal
 * entity, store, permission and module-entitlement restrictions.
 */
final class V2FinancialReportsSidebarAuthorityTest extends TestCase
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

    public function test_financial_reports_are_forbidden_while_finance_module_is_disabled(): void
    {
        [$actor, $store] = $this->operator('P309-DISABLED');

        $this->actingAs($actor)->get($this->url($store))->assertForbidden();
    }

    public function test_scoped_financial_reports_and_statements_are_localized_and_reject_foreign_store(): void
    {
        [$actor, $store, $context] = $this->operator('P309-OWN');
        $this->enableFinance($actor, $context, ['finance.foundation.read', 'finance.gl.read']);
        $foreign = $this->store('P309-FOREIGN');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get($this->url($store))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.reports.title'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'statements']))
                ->assertOk()
                ->assertSee(__('finance_workbench.tabs.statements'));

            $this->actingAs($actor->fresh())->get($this->url($foreign))->assertNotFound();
        }
    }

    public function test_gl_read_does_not_grant_foundation_financial_reports_without_entitlement(): void
    {
        [$actor, $store, $context] = $this->operator('P309-ENTITLEMENT');
        $this->enableFinance($actor, $context, ['finance.gl.read']);

        $this->actingAs($actor->fresh())->get($this->url($store))->assertForbidden();

        $this->enableFinance($actor, $context, ['finance.foundation.read', 'finance.gl.read']);
        $this->actingAs($actor->fresh())->get($this->url($store))->assertOk();
    }

    public function test_explicit_finance_deny_revokes_reports_but_keeps_store_role(): void
    {
        [$actor, $store, $context] = $this->operator('P309-DENY');
        $this->enableFinance($actor, $context, ['finance.foundation.read']);

        $this->actingAs($actor)->get($this->url($store))->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($this->url($store))->assertForbidden();
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_retail_finance_overview_does_not_aggregate_ar_of_sibling_store_in_same_legal_entity(): void
    {
        [$actor, $store, $context] = $this->operator('P309-OVERVIEW');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', 'finance.manage')->firstOrFail()->id,
        ]);
        $actor = $actor->fresh();
        $this->enableFinance($actor, $context, ['finance.foundation.read', 'finance.foundation.manage']);

        $finance = app(FinanceFoundationService::class);
        $year = $finance->createFiscalYear($actor, $context, [
            'code' => 'P309-YEAR',
            'name' => 'P309 fiscal year',
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
        ]);
        $period = $finance->createFiscalPeriod($actor, $context, $year->id, [
            'code' => 'P309-PERIOD',
            'name' => 'P309 fiscal period',
            'sequence' => 1,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
        ]);
        $arAccount = $finance->createAccount($actor, $context, [
            'code' => '1200', 'name' => 'Receivables', 'type' => 'asset',
            'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($actor, $context, [
            'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue',
            'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'P309 fixture customer',
            'email' => 'p309-finance@example.test',
        ]);
        $foreign = $this->store('P309-SIBLING');
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $foreignHierarchy = app(OrganizationHierarchyService::class)->scopeForStore($foreign->id);
        $this->assertNotNull($foreignHierarchy);
        $this->assertSame($scope->legalEntityId, (int) $foreignHierarchy['legal_entity_id']);

        $common = [
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'customer_id' => $customer->id,
            'request_hash' => str_repeat('a', 64),
            'document_type' => 'invoice',
            'entry_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'currency_code' => 'KWD',
            'receivable_account_id' => $arAccount->id,
            'offset_account_id' => $revenue->id,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('enterprise_ar_documents')->insert([
            [...$common, 'store_id' => $store->id, 'document_number' => 'P309-OWN',
                'idempotency_key' => 'p309-own', 'amount' => '12.000'],
            [...$common, 'store_id' => $foreign->id, 'document_number' => 'P309-FOREIGN',
                'idempotency_key' => 'p309-foreign', 'amount' => '900.000'],
        ]);

        // Posted allocations reduce exposure, while a draft allocation must not.
        // The sibling's posted collection must never influence this store's totals.
        $ownDocumentId = DB::table('enterprise_ar_documents')
            ->where('document_number', 'P309-OWN')->value('id');
        $siblingDocumentId = DB::table('enterprise_ar_documents')
            ->where('document_number', 'P309-FOREIGN')->value('id');
        $allocation = [
            'request_hash' => str_repeat('b', 64),
            'allocation_date' => now()->toDateString(),
            'settlement_account_id' => $arAccount->id,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('enterprise_ar_allocations')->insert([
            [...$allocation, 'ar_document_id' => $ownDocumentId, 'idempotency_key' => 'p309-own-posted',
                'amount' => '4.000', 'status' => 'posted', 'posted_at' => now()],
            [...$allocation, 'ar_document_id' => $ownDocumentId, 'idempotency_key' => 'p309-own-draft',
                'amount' => '7.000', 'status' => 'draft'],
            [...$allocation, 'ar_document_id' => $siblingDocumentId, 'idempotency_key' => 'p309-sibling-posted',
                'amount' => '250.000', 'status' => 'posted', 'posted_at' => now()],
        ]);

        $documentCount = DB::table('enterprise_ar_documents')->count();
        $allocationCount = DB::table('enterprise_ar_allocations')->count();
        $response = $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'overview',
            'period_id' => $period->id,
        ]))->assertOk();

        $data = $response->viewData('data');
        $this->assertEqualsWithDelta(8.0, (float) $data['metrics']['ar_outstanding'], 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $data['metrics']['ar_overdue'], 0.001);
        $this->assertSame(1, array_sum(array_column($data['aging']['ar'], 'documents')));
        $this->assertEqualsWithDelta(8.0, array_sum(array_column($data['aging']['ar'], 'amount')), 0.001);
        $this->assertSame($documentCount, DB::table('enterprise_ar_documents')->count());
        $this->assertSame($allocationCount, DB::table('enterprise_ar_allocations')->count());
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = $this->store($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
        ]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
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

    private function url(Store $store): string
    {
        return route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'reports']);
    }

    /** @param list<string> $capabilities */
    private function enableFinance(User $actor, ResolvedTenantContext $context, array $capabilities): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        if (! app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $capabilities,
            $actor,
        );
    }
}
