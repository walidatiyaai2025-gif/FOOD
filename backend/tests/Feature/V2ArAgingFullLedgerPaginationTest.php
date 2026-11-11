<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsReceivableService;
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
use App\Support\EnterpriseDataScope;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArAgingFullLedgerPaginationTest extends TestCase
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

    public function test_all_126_outstanding_documents_are_paginated_and_totals_do_not_mix_currencies_or_scopes(): void
    {
        [$actor, $store, $context] = $this->operator('AR-AGING-FULL');
        $this->enableFinance($actor, $context, true);
        $finance = app(FinanceFoundationService::class);
        $arAccount = $finance->createAccount($actor, $context, [
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($actor, $context, [
            'code' => '4000',
            'name' => 'Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'AR Aging Customer',
            'email' => 'ar-aging@example.test',
        ]);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $today = CarbonImmutable::today();
        $common = [
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'request_hash' => str_repeat('a', 64),
            'document_type' => 'invoice',
            'entry_date' => $today->subDays(120)->toDateString(),
            'amount' => '10.000',
            'receivable_account_id' => $arAccount->id,
            'offset_account_id' => $revenue->id,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $rows = [];
        for ($i = 1; $i <= 126; $i++) {
            $rows[] = [
                ...$common,
                'document_number' => sprintf('AR-%03d', $i),
                'idempotency_key' => sprintf('ar-aging-%03d', $i),
                'due_date' => $i <= 101
                    ? $today->addDay()->toDateString()
                    : $today->subDays(40)->toDateString(),
                'currency_code' => $i <= 101 ? 'KWD' : 'USD',
            ];
        }
        DB::table('enterprise_ar_documents')->insert($rows);
        $firstId = (int) DB::table('enterprise_ar_documents')->where('document_number', 'AR-001')->value('id');
        foreach (['posted' => '2.000', 'draft' => '8.000'] as $status => $amount) {
            DB::table('enterprise_ar_allocations')->insert([
                'ar_document_id' => $firstId,
                'idempotency_key' => 'ar-aging-alloc-'.$status,
                'request_hash' => str_repeat('b', 64),
                'allocation_date' => $today->toDateString(),
                'amount' => $amount,
                'settlement_account_id' => $arAccount->id,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('enterprise_ar_documents')->insert([
            ...$common,
            'document_number' => 'AR-CANCELLED',
            'idempotency_key' => 'ar-aging-cancelled',
            'due_date' => $today->subDays(100)->toDateString(),
            'currency_code' => 'KWD',
            'amount' => '900.000',
            'status' => 'cancelled',
        ]);

        // Separate legal-entity/store rows cannot enter the current actor's
        // paginated detail or currency/bucket aggregates.
        $foreign = $this->retailStore('AR-AGING-FOREIGN');
        $foreignHierarchy = app(OrganizationHierarchyService::class)->scopeForStore($foreign->id);
        $this->assertNotNull($foreignHierarchy);
        $foreignScope = EnterpriseDataScope::fromHierarchy($foreignHierarchy);
        $this->assertSame($scope->legalEntityId, $foreignScope->legalEntityId);
        DB::table('enterprise_ar_documents')->insert([
            ...$common,
            'organization_id' => $foreignScope->organizationId,
            'company_id' => $foreignScope->companyId,
            'legal_entity_id' => $foreignScope->legalEntityId,
            'store_id' => $foreign->id,
            'document_number' => 'AR-FOREIGN',
            'idempotency_key' => 'ar-aging-foreign',
            'due_date' => $today->toDateString(),
            'currency_code' => 'KWD',
            'amount' => '999.000',
        ]);

        $service = app(AccountsReceivableService::class);
        $totals = $service->agingTotals($actor, $context);
        $this->assertCount(2, $totals);
        $kwd = $totals->first(fn ($bucket) => $bucket->currency_code === 'KWD' && $bucket->aging_bucket === 'current');
        $usd = $totals->first(fn ($bucket) => $bucket->currency_code === 'USD' && $bucket->aging_bucket === '31_60');
        $this->assertNotNull($kwd);
        $this->assertNotNull($usd);
        $this->assertSame(101, (int) $kwd->document_count);
        $this->assertEqualsWithDelta(1008.0, (float) $kwd->outstanding_amount, 0.001);
        $this->assertSame(25, (int) $usd->document_count);
        $this->assertEqualsWithDelta(250.0, (float) $usd->outstanding_amount, 0.001);

        $page = $service->paginateAging($actor, $context);
        $this->assertSame(126, $page->total());
        $this->assertSame(25, $page->perPage());
        $this->assertSame(6, $page->lastPage());
        $this->assertFalse($page->getCollection()->contains(fn ($row) => $row->document_number === 'AR-FOREIGN'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                    'aging_page' => 6,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('data', static fn (array $data): bool => $data['aging']->total() === 126
                    && $data['aging']->currentPage() === 6
                    && $data['aging']->count() === 1
                    && $data['agingTotals']->count() === 2);
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $foreign->id, 'tab' => 'receivables']))
            ->assertNotFound();
    }

    public function test_aging_requires_ar_entitlement_and_explicit_denies_take_precedence(): void
    {
        [$actor, $store, $context] = $this->operator('AR-AGING-DENY');
        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'receivables']);

        $this->actingAs($actor)->get($url)->assertForbidden();
        $this->enableFinance($actor, $context, false);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
        $this->enableFinance($actor, $context, true);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = $this->retailStore($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();
        $permissionIds = Permission::query()
            ->whereIn('code', ['finance.view', 'finance.manage'])
            ->pluck('id')
            ->all();
        $role->permissions()->syncWithoutDetaching($permissionIds);
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

    private function retailStore(string $code): Store
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

    private function enableFinance(User $actor, ResolvedTenantContext $context, bool $ar): void
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
            $ar
                ? ['finance.foundation.read', 'finance.foundation.manage', 'finance.ar.read']
                : ['finance.foundation.read', 'finance.foundation.manage'],
            $actor,
        );
    }
}
