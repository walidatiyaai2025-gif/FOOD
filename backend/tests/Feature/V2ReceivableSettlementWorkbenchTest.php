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
use App\Models\EnterpriseAccountsReceivableAllocation;
use App\Models\EnterpriseAccountsReceivableDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2ReceivableSettlementWorkbenchTest extends TestCase
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

    public function test_bilingual_ar_draft_refuses_unverified_collection_posting_without_journal(): void
    {
        $fixture = $this->fixture('P303-SETTLEMENT');
        $maker = $fixture['maker'];
        $poster = $fixture['poster'];
        $store = $fixture['store'];
        $document = $fixture['document'];

        foreach (['en', 'ar'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                    'settlement_document_id' => $document->id,
                ]))
                ->assertOk()
                ->assertSee('data-v2-ar-settlements', false)
                ->assertSee('data-v2-ar-settlement-create', false)
                ->assertSee(__('finance_workbench.receivables.settlements', [], $locale))
                // P303 is not receipt-certified: both locales must disclose that
                // a manually entered reference is NOT proof of collected funds.
                ->assertSee(__('finance_workbench.receivables.reference_notice', [], $locale));
        }

        $payload = [
            'store_id' => $store->id,
            'idempotency_key' => 'p303-doc-collection-001',
            'allocation_date' => '2027-01-12',
            'amount' => '40.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'P303-RECEIPT-TEST-001',
        ];

        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.store', $document->id),
            $payload,
        )->assertRedirect();

        $allocation = EnterpriseAccountsReceivableAllocation::query()->sole();
        $this->assertSame('draft', $allocation->status);
        $this->assertSame($maker->id, $allocation->created_by);

        // Replaying the identical request cannot create another allocation.
        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.store', $document->id),
            $payload,
        )->assertRedirect();
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);

        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.post', $allocation->id),
            ['store_id' => $store->id],
        )->assertSessionHasErrors('poster');
        $this->assertSame('draft', $allocation->fresh()->status);

        foreach (['en', 'ar'] as $locale) {
            $poster->forceFill(['locale' => $locale])->save();
            $this->actingAs($poster->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                    'settlement_document_id' => $document->id,
                ]))
                ->assertOk()
                ->assertSee('data-v2-ar-source-unverified', false)
                ->assertSee(__('finance_workbench.receivables.verification_pending', [], $locale))
                ->assertDontSee('data-v2-ar-settlement-post', false);
        }

        // A manually supplied receipt reference is NOT collection provenance.
        // Direct HTTP must also reject attempts even by an authorized poster.
        $this->actingAs($poster)->post(
            route('admin.erp.finance.receivables.settlements.post', $allocation->id),
            ['store_id' => $store->id],
        )->assertSessionHasErrors('collection_source');

        $this->assertSame('draft', $allocation->fresh()->status);
        $this->assertNull($allocation->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.ar_allocation_posted',
            'auditable_id' => $allocation->id,
        ]);

        $this->actingAs($poster)->post(
            route('admin.erp.finance.receivables.settlements.post', $allocation->id),
            ['store_id' => $store->id],
        )->assertSessionHasErrors('collection_source');
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_posted_legacy_allocation_cannot_be_replayed_as_verified_collection(): void
    {
        $fixture = $this->fixture('P303-POSTED-REPLAY');
        $tenants = app(TenantContextResolver::class);
        $ar = app(AccountsReceivableService::class);
        $makerContext = $tenants->retail($fixture['maker'], $fixture['store']->id);
        $posterContext = $tenants->retail($fixture['poster'], $fixture['store']->id);
        $allocation = $ar->createAllocation($fixture['maker'], $makerContext, $fixture['document']->id, [
            'idempotency_key' => 'p303-posted-replay',
            'allocation_date' => '2027-01-12',
            'amount' => '10.000',
            'settlement_account_id' => $fixture['cash_account_id'],
        ]);
        $posted = $ar->postAllocation($fixture['poster'], $posterContext, $allocation->id);
        $this->assertSame('posted', $posted->status);
        $journalId = $posted->journal_id;

        try {
            $ar->postAllocation($fixture['poster'], $posterContext, $allocation->id, requiresVerifiedCollectionSource: true);
            $this->fail('A posted legacy AR allocation must not be accepted as receipt-verified.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection_source', $exception->errors());
        }

        $this->assertSame('posted', $posted->fresh()->status);
        $this->assertSame($journalId, $posted->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 2);
    }

    public function test_direct_http_denies_cross_store_and_over_allocation_without_financial_mutation(): void
    {
        $fixture = $this->fixture('P303-BOUNDARY');
        $store = $fixture['store'];
        $document = $fixture['document'];
        $maker = $fixture['maker'];
        $foreign = $this->retailStore('P303-OTHER');
        $actor = $this->userForStore($foreign, 'P303-FOREIGN');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
                'settlement_document_id' => $document->id,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('admin.erp.finance.receivables.settlements.store', $document->id), [
                'store_id' => $store->id,
                'idempotency_key' => 'p303-cross-store',
                'allocation_date' => '2027-01-12',
                'amount' => '1.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ])->assertNotFound();

        $this->actingAs($maker)
            ->post(route('admin.erp.finance.receivables.settlements.store', $document->id), [
                'store_id' => $store->id,
                'idempotency_key' => 'p303-overallocate',
                'allocation_date' => '2027-01-12',
                'amount' => '101.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('enterprise_ar_allocations', 0);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_retail_store_cannot_settle_sibling_store_document_in_the_same_legal_entity(): void
    {
        $fixture = $this->fixture('P303-ONE-STORE');
        $sibling = $this->retailStore('P303-SIBLING');
        $siblingMaker = $this->userForStore($sibling, 'P303-SIBLING-MAKER');
        $siblingPoster = $this->userForStore($sibling, 'P303-SIBLING-POSTER');
        $tenants = app(TenantContextResolver::class);
        $currentContext = $tenants->retail($fixture['maker'], $fixture['store']->id);
        $siblingContext = $tenants->retail($siblingMaker, $sibling->id);
        $siblingPosterContext = $tenants->retail($siblingPoster, $sibling->id);

        // Two stores intentionally share the default enterprise legal entity.
        $this->assertSame(
            $currentContext->enterpriseScope->legalEntityId,
            $siblingContext->enterpriseScope->legalEntityId,
        );

        $ar = app(AccountsReceivableService::class);
        $foreignDocument = $ar->createDraft($siblingMaker, $siblingContext, [
            'idempotency_key' => 'p303-sibling-doc',
            'store_id' => $sibling->id,
            'customer_id' => $fixture['document']->customer_id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '30.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $fixture['document']->receivable_account_id,
            'offset_account_id' => $fixture['document']->offset_account_id,
        ]);
        $ar->post($siblingPoster, $siblingPosterContext, $foreignDocument->id);
        $foreignAllocation = $ar->createAllocation($siblingMaker, $siblingContext, $foreignDocument->id, [
            'idempotency_key' => 'p303-sibling-allocation',
            'allocation_date' => '2027-01-12',
            'amount' => '10.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'P303-SIBLING',
        ]);

        // A previously posted legacy allocation must still be scoped on
        // idempotent retries, not just on the initial financial transition.
        $postedSiblingAllocation = $ar->postAllocation($siblingPoster, $siblingPosterContext, $foreignAllocation->id);
        $this->assertSame('posted', $postedSiblingAllocation->status);

        $current = $fixture['maker'];
        $currentStoreId = $fixture['store']->id;
        $this->actingAs($current)
            ->get(route('admin.erp.finance.index', ['store_id' => $currentStoreId, 'tab' => 'receivables']))
            ->assertOk()
            ->assertDontSee($foreignDocument->document_number);
        $this->actingAs($current)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $currentStoreId,
                'tab' => 'receivables',
                'settlement_document_id' => $foreignDocument->id,
            ]))
            ->assertNotFound();

        $this->actingAs($current)->post(
            route('admin.erp.finance.receivables.settlements.store', $foreignDocument->id),
            [
                'store_id' => $currentStoreId,
                'idempotency_key' => 'p303-cross-store-allocation',
                'allocation_date' => '2027-01-12',
                'amount' => '1.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ],
        )->assertNotFound();

        $this->actingAs($current)->post(
            route('admin.erp.finance.receivables.settlements.post', $foreignAllocation->id),
            ['store_id' => $currentStoreId],
        )->assertNotFound();

        $this->assertSame('draft', $foreignAllocation->fresh()->status);
        $this->assertDatabaseMissing('enterprise_ar_allocations', [
            'ar_document_id' => $foreignDocument->id,
            'idempotency_key' => 'p303-cross-store-allocation',
        ]);

        // The controller already denies this posted ID. Direct service calls
        // must also reject cross-store posted replays before returning status.
        $this->expectException(ModelNotFoundException::class);
        $ar->postAllocation($current, $currentContext, $foreignAllocation->id);
    }

    /** @return array{store:Store,maker:User,poster:User,document:EnterpriseAccountsReceivableDocument,cash_account_id:int} */
    private function fixture(string $prefix): array
    {
        $store = $this->retailStore($prefix);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        foreach (['finance.view', 'finance.manage', 'finance.post'] as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $maker = $this->userForStore($store, $prefix.'-MAKER');
        $poster = $this->userForStore($store, $prefix.'-POSTER');
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $this->enableFinance($maker, $context);

        $finance = app(FinanceFoundationService::class);
        $receivable = $finance->createAccount($maker, $context, [
            'code' => '1200', 'name' => 'Accounts Receivable',
            'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $context, [
            'code' => '4000', 'name' => 'Sales Revenue',
            'type' => 'revenue', 'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $cash = $finance->createAccount($maker, $context, [
            'code' => '1100', 'name' => 'Cash',
            'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $context, [
            'code' => 'FY2027', 'name' => 'Fiscal year 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $context, $year->id, [
            'sequence' => 1, 'code' => 'P01', 'name' => 'January 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
        ]);

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => $prefix.' Customer',
            'email' => strtolower($prefix).'@example.test',
        ]);
        $receivables = app(AccountsReceivableService::class);
        $document = $receivables->createDraft($maker, $context, [
            'idempotency_key' => $prefix.'-ar-doc',
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $receivable->id,
            'offset_account_id' => $revenue->id,
        ]);
        $receivables->post($poster, $posterContext, $document->id);

        return [
            'store' => $store,
            'maker' => $maker,
            'poster' => $poster,
            'document' => $document->fresh(),
            'cash_account_id' => $cash->id,
        ];
    }

    private function retailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userForStore(Store $store, string $code): User
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user = User::factory()->create([
            'email' => strtolower($code).'@example.test',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.gl.manage',
                'finance.gl.post',
                'finance.ar.read',
                'finance.ar.manage',
                'finance.ar.post',
            ],
            $actor,
        );
    }
}
