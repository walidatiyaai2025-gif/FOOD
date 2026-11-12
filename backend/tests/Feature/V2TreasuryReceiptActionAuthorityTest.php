<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseCashbox;
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
 * P295: Treasury receipt actions must mirror the service's store entitlement
 * and maker/checker decisions; hiding an action never grants HTTP authority.
 */
final class V2TreasuryReceiptActionAuthorityTest extends TestCase
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

    public function test_arabic_maker_cannot_approve_own_receipt_but_independent_english_checker_can(): void
    {
        $store = $this->retailStore('V2-P295-MAKER');
        $maker = $this->operator($store, 'ar');
        $checker = $this->operator($store, 'en');
        $this->enableTreasury($maker, $store);
        $cashbox = $this->cashbox($store);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.receipts.store'), $this->payload($store, $cashbox, 'v2-p295-maker-001'))
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'receipts', 'store_id' => $store->id,
            ]));

        $receipt = EnterpriseTreasuryReceipt::query()->where('idempotency_key', 'v2-p295-maker-001')->firstOrFail();

        app()->setLocale('ar');
        $this->actingAs($maker->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id, 'tab' => 'receipts']))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee($receipt->receipt_number)
            ->assertSee(route('admin.erp.treasury.receipts.cancel', $receipt))
            ->assertDontSee(route('admin.erp.treasury.receipts.post', $receipt));

        $this->actingAs($maker->fresh())
            ->post(route('admin.erp.treasury.receipts.post', $receipt), ['store_id' => $store->id])
            ->assertSessionHasErrors('approver');
        $this->assertSame('draft', $receipt->fresh()->status);

        app()->setLocale('en');
        $this->actingAs($checker->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id, 'tab' => 'receipts']))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee($receipt->receipt_number)
            ->assertSee(route('admin.erp.treasury.receipts.post', $receipt));

        $this->actingAs($checker->fresh())
            ->post(route('admin.erp.treasury.receipts.post', $receipt), ['store_id' => $store->id])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'receipts', 'store_id' => $store->id,
            ]));

        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'id' => $receipt->id,
            'status' => 'posted',
            'posted_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.receipt_posted',
            'auditable_id' => $receipt->id,
        ]);
    }

    public function test_finance_manager_with_only_receipt_read_capability_cannot_see_or_execute_mutations(): void
    {
        $store = $this->retailStore('V2-P295-READ');
        $actor = $this->operator($store, 'en');
        $this->enableTreasury($actor, $store);
        $cashbox = $this->cashbox($store);

        $this->actingAs($actor)
            ->post(route('admin.erp.treasury.receipts.store'), $this->payload($store, $cashbox, 'v2-p295-read-draft'))
            ->assertRedirect();
        $receipt = EnterpriseTreasuryReceipt::query()->where('idempotency_key', 'v2-p295-read-draft')->firstOrFail();

        $this->entitle($actor, $store, ['treasury.receipts.read']);
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id, 'tab' => 'receipts']))
            ->assertOk()
            ->assertSee($receipt->receipt_number)
            ->assertDontSee(route('admin.erp.treasury.receipts.store'))
            ->assertDontSee(route('admin.erp.treasury.receipts.post', $receipt))
            ->assertDontSee(route('admin.erp.treasury.receipts.cancel', $receipt));

        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.treasury.receipts.store'), $this->payload($store, $cashbox, 'v2-p295-denied'))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.treasury.receipts.cancel', $receipt), ['store_id' => $store->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['idempotency_key' => 'v2-p295-denied']);
        $this->assertSame('draft', $receipt->fresh()->status);
    }

    public function test_foreign_store_and_unentitled_receipt_ids_do_not_leak_or_mutate(): void
    {
        $own = $this->retailStore('V2-P295-OWN');
        $foreign = $this->retailStore('V2-P295-FOREIGN');
        $maker = $this->operator($own, 'en');
        $other = $this->operator($foreign, 'en');
        $this->enableTreasury($maker, $own);
        $this->entitle($other, $foreign, [
            'treasury.receipts.read',
            'treasury.receipts.manage',
            'treasury.receipts.approve',
        ]);
        $cashbox = $this->cashbox($own);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.receipts.store'), $this->payload($own, $cashbox, 'v2-p295-own-only'))
            ->assertRedirect();
        $receipt = EnterpriseTreasuryReceipt::query()->where('idempotency_key', 'v2-p295-own-only')->firstOrFail();

        $this->actingAs($other)
            ->get(route('admin.erp.treasury.index', ['store_id' => $own->id, 'tab' => 'receipts']))
            ->assertForbidden();
        $this->actingAs($other)
            ->post(route('admin.erp.treasury.receipts.post', $receipt), ['store_id' => $foreign->id])
            ->assertNotFound();
        $this->actingAs($other)
            ->post(route('admin.erp.treasury.receipts.cancel', $receipt), ['store_id' => $foreign->id])
            ->assertNotFound();

        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.treasury.receipt_posted',
            'auditable_id' => $receipt->id,
        ]);
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

    private function operator(Store $store, string $locale): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function enableTreasury(User $actor, Store $store): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $this->entitle($actor, $store, [
            'treasury.receipts.read',
            'treasury.receipts.manage',
            'treasury.receipts.approve',
        ]);
    }

    /** @param list<string> $capabilities */
    private function entitle(User $actor, Store $store, array $capabilities): void
    {
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

    private function cashbox(Store $store): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'V2-P295-CASH-'.$store->id,
            'name' => 'Treasury receipt cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    /** @return array<string, int|string> */
    private function payload(Store $store, EnterpriseCashbox $cashbox, string $key): array
    {
        return [
            'store_id' => $store->id,
            '_operation_key' => $key,
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '18.250',
            'value_date' => '2026-11-10',
            'reference' => 'V2-P295-RECEIPT',
            'description' => 'Treasury receipt authority proof',
        ];
    }
}
