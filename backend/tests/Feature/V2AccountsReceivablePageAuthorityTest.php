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
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 P286: the existing Finance > Receivables tab is the real AR customer
 * account/document workspace, distinct from the P288 aging-report tests.
 */
final class V2AccountsReceivablePageAuthorityTest extends TestCase
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

    public function test_customer_ar_documents_are_real_store_scoped_and_bilingual(): void
    {
        [$alice, $storeA, $contextA] = $this->operator('P286-A');
        [$bob, $storeB, $contextB] = $this->operator('P286-B');
        $this->enableFinance($alice, $contextA);
        $this->enableFinance($bob, $contextB);

        $this->createCustomerDraft($alice, $storeA, $contextA, 'A');
        $this->createCustomerDraft($bob, $storeB, $contextB, 'B');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $alice->forceFill(['locale' => $locale])->save();

            $this->actingAs($alice->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $storeA->id,
                    'tab' => 'receivables',
                    'status' => 'draft',
                ]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.receivables.title'))
                ->assertSee('P286-A Customer')
                ->assertDontSee('P286-B Customer');
        }

        $this->actingAs($bob->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeB->id,
                'tab' => 'receivables',
            ]))
            ->assertOk()
            ->assertSee('P286-B Customer')
            ->assertDontSee('P286-A Customer');

        $this->actingAs($alice->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeB->id,
                'tab' => 'receivables',
            ]))
            ->assertNotFound();

        // The P286 document-status filter is independent of P288 aging.
        $this->actingAs($alice->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeA->id,
                'tab' => 'receivables',
                'status' => 'posted',
            ]))
            ->assertOk()
            ->assertDontSee('P286-A Customer');
    }

    public function test_ar_tab_requires_enabled_ready_module_and_specific_ar_entitlement(): void
    {
        [$actor, $store, $context] = $this->operator('P286-ENTITLEMENT');
        $url = route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
        ]);

        $this->actingAs($actor)->get($url)->assertForbidden();

        $this->enableFinance($actor, $context, false);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $this->enableFinance($actor, $context);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
    }

    public function test_ar_reader_cannot_mutate_documents_and_global_deny_revokes_read(): void
    {
        [$actor, $store, $context] = $this->operator('P286-READONLY');
        $this->enableFinance($actor, $context);
        $this->createCustomerDraft($actor, $store, $context, 'R');

        $this->deny($actor, 'finance.manage');

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ]))
            ->assertOk()
            ->assertSee('P286-R Customer');

        try {
            app(AccountsReceivableService::class)->createDraft($actor->fresh(), $context, []);
            $this->fail('A read-only AR actor must not create a document.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('enterprise_ar_documents', 1);

        $this->deny($actor, 'finance.view');
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ]))
            ->assertForbidden();
        $this->assertDatabaseCount('enterprise_ar_documents', 1);
    }

    public function test_roleless_or_inactive_actor_cannot_access_customer_ar(): void
    {
        [$actor, $store, $context] = $this->operator('P286-INACTIVE');
        $this->enableFinance($actor, $context);
        $actor->forceFill(['is_active' => false])->save();

        $url = route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
        ]);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($roleless);
        $this->actingAs($roleless)->get($url)->assertForbidden();
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(string $code): array
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->firstOrFail();

        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('code', [
                'finance.view', 'finance.manage',
            ])->pluck('id'),
        );

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

    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        bool $withAr = true,
    ): void {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        if (! app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        $capabilities = ['finance.foundation.read', 'finance.foundation.manage'];
        if ($withAr) {
            $capabilities = [...$capabilities, 'finance.ar.read', 'finance.ar.manage'];
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $capabilities,
            $actor,
        );
    }

    private function createCustomerDraft(
        User $actor,
        Store $store,
        ResolvedTenantContext $context,
        string $suffix,
    ): void {
        $finance = app(FinanceFoundationService::class);
        $receivable = $finance->createAccount($actor, $context, [
            'code' => '1200'.$suffix,
            'name' => 'Accounts Receivable '.$suffix,
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($actor, $context, [
            'code' => '4000'.$suffix,
            'name' => 'Sales Revenue '.$suffix,
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'P286-'.$suffix.' Customer',
            'email' => 'p286-'.strtolower($suffix).'@example.test',
        ]);

        app(AccountsReceivableService::class)->createDraft($actor, $context, [
            'idempotency_key' => 'P286-AR-'.$suffix,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $receivable->id,
            'offset_account_id' => $revenue->id,
        ]);
    }

    private function deny(User $actor, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
