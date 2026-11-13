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
use App\Models\EnterpriseAccountsPayableDocument;
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
 * P287: the existing AP tab is the canonical scoped business destination.
 */
final class V2AccountsPayablePageAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_authorized_ap_documents_are_store_scoped_and_ar_en_localized(): void
    {
        [$alice, $storeA, $contextA] = $this->operator('P287-A', 'en');
        [$bob, $storeB, $contextB] = $this->operator('P287-B', 'en');
        $this->enableFinance($alice, $contextA);
        $this->enableFinance($bob, $contextB);

        $this->createApDocument($alice, $storeA, $contextA, 'A', 'P287-SUPPLIER-A');
        $this->createApDocument($bob, $storeB, $contextB, 'B', 'P287-SUPPLIER-B');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $alice->forceFill(['locale' => $locale])->save();

            $this->actingAs($alice->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $storeA->id,
                    'tab' => 'payables',
                ]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.payables.title'))
                ->assertSee('P287-SUPPLIER-A')
                ->assertDontSee('P287-SUPPLIER-B');
        }

        $this->actingAs($bob->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $storeB->id, 'tab' => 'payables']))
            ->assertOk()
            ->assertSee('P287-SUPPLIER-B')
            ->assertDontSee('P287-SUPPLIER-A');
    }

    public function test_p299_posted_ap_accrual_only_enters_the_authorized_outstanding_read_model_after_separate_posting(): void
    {
        [$maker, $store, $context] = $this->operator('P299-ACCRUAL', 'en');
        $this->enableFinance($maker, $context, [
            'finance.foundation.read',
            'finance.foundation.manage',
            'finance.gl.read',
            'finance.gl.manage',
            'finance.gl.post',
            'finance.ap.read',
            'finance.ap.manage',
            'finance.ap.post',
        ]);

        $finance = app(FinanceFoundationService::class);
        $year = $finance->createFiscalYear($maker, $context, [
            'code' => 'P299FY2027',
            'name' => 'P299 Accrual Fiscal Year',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $context, $year->id, [
            'sequence' => 1,
            'code' => 'P01',
            'name' => 'P299 January',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
        ]);

        $draft = $this->createApDocument($maker, $store, $context, 'DRAFT', 'P299-DRAFT-UNPOSTED');
        $accrual = $this->createApDocument($maker, $store, $context, 'POST', 'P299-ACCRUED-POSTED');
        $service = app(AccountsPayableService::class);
        $this->assertFalse($maker->hasPermission('finance.post', $store->id));

        $before = $this->actingAs($maker->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))
            ->assertOk()
            ->assertSee('P299-DRAFT-UNPOSTED')
            ->assertSee('P299-ACCRUED-POSTED');
        $before->assertViewHas('data', static fn (array $data): bool => $data['aging']->isEmpty());
        $this->assertDatabaseCount('enterprise_finance_journals', 0);

        // A dedicated same-store checker can post, but cannot substitute a
        // roleless or foreign-tenant identity for the maker's legal entity.
        $posterRole = Role::query()->create([
            'code' => 'P299_POSTER',
            'name' => 'P299 independent AP poster',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $posterRole->permissions()->sync(
            Permission::query()->whereIn('code', ['finance.view', 'finance.post'])->pluck('id')->all(),
        );
        $poster = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->grantPlatformErpAccess($poster);
        DB::table('user_store_roles')->insert([
            'user_id' => $poster->id,
            'store_id' => $store->id,
            'role_id' => $posterRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);

        $posted = $service->post($poster, $posterContext, $accrual->id);
        $retry = $service->post($poster, $posterContext, $accrual->id);
        $this->assertSame($posted->id, $retry->id);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->journal_id);
        $this->assertDatabaseHas('enterprise_ap_documents', [
            'id' => $draft->id, 'status' => 'draft',
        ]);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseCount('enterprise_ap_allocations', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.ap_document_posted',
            'auditable_id' => $posted->id,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $maker->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($maker->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id, 'tab' => 'payables',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.payables.title'));

            $response->assertViewHas('data', static function (array $data) use ($posted): bool {
                $accrued = $data['aging'];
                return $accrued->count() === 1
                    && (int) $accrued->first()->id === (int) $posted->id
                    && (float) $accrued->first()->amount === 100.0
                    && (float) $accrued->first()->outstanding_amount === 100.0;
            });
        }
    }

    public function test_ap_route_denies_foreign_store_and_restricted_role(): void
    {
        [$alice, $storeA, $context] = $this->operator('P287-OWN');
        [$bob, $storeB] = $this->operator('P287-OTHER');
        $this->enableFinance($alice, $context);

        $this->actingAs($alice)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $storeB->id,
                'tab' => 'payables',
                'supplier_reference' => 'FORGED',
            ]))
            ->assertNotFound();

        $this->actingAs($bob)
            ->get(route('admin.erp.finance.index', ['store_id' => $storeA->id, 'tab' => 'payables']))
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($roleless);

        $this->actingAs($roleless)
            ->get(route('admin.erp.finance.index', ['store_id' => $storeA->id, 'tab' => 'payables']))
            ->assertForbidden();
    }

    public function test_ap_tab_fails_closed_while_finance_module_is_disabled(): void
    {
        [$actor, $store] = $this->operator('P287-OFF');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'payables']))
            ->assertForbidden();
    }

    public function test_ap_requires_ap_read_entitlement_not_just_finance_foundation(): void
    {
        [$actor, $store, $context] = $this->operator('P287-NO-AP');
        $this->enableFinance($actor, $context, ['finance.foundation.read']);

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'payables']))
            ->assertForbidden();
    }

    public function test_explicit_finance_view_deny_overrides_role_permission_for_ap(): void
    {
        [$actor, $store, $context] = $this->operator('P287-DENY');
        $this->enableFinance($actor, $context);
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'payables']))
            ->assertOk();

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();

        // The deny must win over the aggregate grants of multiple store roles.
        $additionalRole = Role::query()->create([
            'code' => 'P287_EXTRA_FINANCE_READER',
            'name' => 'P287 additional Finance reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $additionalRole->permissions()->attach($permission->id);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $additionalRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'payables']))
            ->assertForbidden();
    }

    /**
     * @return array{User, Store, ResolvedTenantContext}
     */
    private function operator(string $code, string $locale = 'en'): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        foreach (['finance.view', 'finance.manage'] as $codePermission) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $codePermission],
                ['name' => $codePermission],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);
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

    /** @param list<string>|null $capabilities */
    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        ?array $capabilities = null,
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
            $capabilities ?? [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.ap.read',
                'finance.ap.manage',
            ],
            $actor,
        );
    }

    private function createApDocument(
        User $actor,
        Store $store,
        ResolvedTenantContext $context,
        string $suffix,
        string $supplierReference,
    ): EnterpriseAccountsPayableDocument {
        $finance = app(FinanceFoundationService::class);
        $payable = $finance->createAccount($actor, $context, [
            'code' => '2100'.$suffix,
            'name' => 'AP Liability '.$suffix,
            'type' => 'liability',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $offset = $finance->createAccount($actor, $context, [
            'code' => '5100'.$suffix,
            'name' => 'AP Expense '.$suffix,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);

        return app(AccountsPayableService::class)->createDraft($actor, $context, [
            'idempotency_key' => 'P287-AP-'.$suffix,
            'store_id' => $store->id,
            'supplier_reference' => $supplierReference,
            'supplier_name' => $supplierReference,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'payable_account_id' => $payable->id,
            'offset_account_id' => $offset->id,
        ]);
    }
}
