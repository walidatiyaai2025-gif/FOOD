<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P290: match an actual posted bank movement inside the authoritative
 * Treasury reconciliation tab. Never replace this with a decorative link.
 */
final class V2TreasuryLineSettlementAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_posted_bank_receipt_is_matched_idempotently_and_closed_only_by_an_independent_checker(): void
    {
        $store = $this->store('P290-OWN');
        $maker = $this->operator($store, 'en');
        $checker = $this->operator($store, 'en');
        $this->entitle($maker, $store, $this->capabilities());
        $bank = $this->bank($store);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'p290-receipt-001',
                'target_key' => 'bank_account:'.$bank->id,
                'amount' => '32.125',
                'value_date' => now()->toDateString(),
                'reference' => 'P290-POSTED-BANK-MOVEMENT',
            ])
            ->assertRedirect();

        $receipt = EnterpriseTreasuryReceipt::query()
            ->where('idempotency_key', 'p290-receipt-001')
            ->firstOrFail();

        $this->actingAs($checker)
            ->post(route('admin.erp.treasury.receipts.post', $receipt), ['store_id' => $store->id])
            ->assertRedirect();
        $this->assertSame('posted', $receipt->fresh()->status);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.reconciliation.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'p290-reconciliation-001',
                'bank_account_id' => $bank->id,
                'period_from' => now()->subDay()->toDateString(),
                'period_to' => now()->addDay()->toDateString(),
                'statement_receipts_total' => '32.125',
                'statement_payments_total' => '0.000',
            ])
            ->assertRedirect();

        $reconciliation = DB::table('enterprise_treasury_reconciliations')
            ->where('idempotency_key', 'p290-reconciliation-001')
            ->first();
        $this->assertNotNull($reconciliation);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();

            $this->actingAs($maker->fresh())
                ->get(route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => 'reconciliation',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($bank->code)
                ->assertSee('name="transaction_key"', false)
                ->assertSee('receipt:'.$receipt->id, false)
                ->assertDontSee('name="transaction_id"', false)
                ->assertDontSee(route('admin.erp.treasury.reconciliation.close', $reconciliation->id), false);
        }

        $matchUrl = route('admin.erp.treasury.reconciliation.matches.store', $reconciliation->id);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($maker->fresh())
                ->post($matchUrl, [
                    'store_id' => $store->id,
                    'transaction_key' => 'receipt:'.$receipt->id,
                ])
                ->assertRedirect();
        }

        $this->assertDatabaseCount('enterprise_treasury_reconciliation_lines', 1);
        $this->assertDatabaseHas('enterprise_treasury_reconciliation_lines', [
            'reconciliation_id' => $reconciliation->id,
            'transaction_type' => 'receipt',
            'transaction_id' => $receipt->id,
        ]);

        $closeUrl = route('admin.erp.treasury.reconciliation.close', $reconciliation->id);
        $this->actingAs($maker->fresh())
            ->post($closeUrl, ['store_id' => $store->id])
            ->assertSessionHasErrors('approver');

        $this->actingAs($checker)
            ->post($closeUrl, ['store_id' => $store->id])
            ->assertRedirect();

        $this->assertDatabaseHas('enterprise_treasury_reconciliations', [
            'id' => $reconciliation->id,
            'status' => 'closed',
            'closed_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.reconciliation_match_added',
            'auditable_id' => $reconciliation->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.reconciliation_closed',
            'auditable_id' => $reconciliation->id,
        ]);

        $this->actingAs($maker->fresh())
            ->post($matchUrl, [
                'store_id' => $store->id,
                'transaction_key' => 'receipt:'.$receipt->id,
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('enterprise_treasury_reconciliation_lines', 1);
    }

    public function test_foreign_store_cannot_read_or_match_another_stores_reconciliation(): void
    {
        $store = $this->store('P290-SOURCE');
        $foreignStore = $this->store('P290-FOREIGN');
        $maker = $this->operator($store, 'en');
        $foreign = $this->operator($foreignStore, 'en');
        $this->entitle($maker, $store, $this->capabilities());
        $this->entitle($foreign, $foreignStore, $this->capabilities());
        $bank = $this->bank($store);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.reconciliation.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'p290-private-reconciliation',
                'bank_account_id' => $bank->id,
                'period_from' => now()->subDay()->toDateString(),
                'period_to' => now()->toDateString(),
                'statement_receipts_total' => '0.000',
                'statement_payments_total' => '0.000',
            ])
            ->assertRedirect();

        $id = (int) DB::table('enterprise_treasury_reconciliations')
            ->where('idempotency_key', 'p290-private-reconciliation')
            ->value('id');
        $this->assertGreaterThan(0, $id);

        $this->actingAs($foreign)
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id, 'tab' => 'reconciliation']))
            ->assertForbidden();

        $this->actingAs($foreign)
            ->post(route('admin.erp.treasury.reconciliation.matches.store', $id), [
                'store_id' => $foreignStore->id,
                'transaction_key' => 'receipt:999999',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('enterprise_treasury_reconciliation_lines', 0);
        $this->assertDatabaseHas('enterprise_treasury_reconciliations', [
            'id' => $id,
            'status' => 'draft',
        ]);
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

    private function operator(Store $store, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function bank(Store $store): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'P290-BANK-'.$store->id,
            'name' => 'Bank reconciliation account',
            'bank_name' => 'FOODEX Treasury Bank',
            'account_name' => 'FOODEX Treasury',
            'account_reference' => 'KW00TEST1234',
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function capabilities(): array
    {
        return [
            'treasury.receipts.read',
            'treasury.receipts.manage',
            'treasury.receipts.approve',
            'treasury.reconciliation.read',
            'treasury.reconciliation.manage',
            'treasury.reconciliation.approve',
        ];
    }

    /** @param list<string> $capabilities */
    private function entitle(User $actor, Store $store, array $capabilities): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            $capabilities,
            $actor,
        );
    }
}
