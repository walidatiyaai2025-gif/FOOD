<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2619 / P289 — receipts reuse the real protected Treasury workbench,
 * with entitlement-authorized operations and independent posting.
 */
final class V2TreasuryReceiptsNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_receipts_page_is_bilingual_and_denies_disabled_or_unentitled_module(): void
    {
        $store = $this->store('P289-READ');
        $arabic = $this->operator($store, 'ar');
        $page = route('admin.erp.treasury.index', ['store_id' => $store->id, 'tab' => 'receipts']);

        // No fake/dead-link success while the Treasury module is unready.
        $this->actingAs($arabic)->get($page)->assertForbidden();

        // Grant only receipt-read, not create/post; Finance role alone is insufficient.
        $this->enableTreasury($arabic, $store, ['treasury.receipts.read']);
        $this->actingAs($arabic)->get($page)
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مركز عمل الخزينة')
            ->assertSee('data-erp-treasury-workbench="true"', false)
            ->assertDontSee('action="'.route('admin.erp.treasury.receipts.store').'"', false);

        $this->actingAs($arabic)->post(route('admin.erp.treasury.receipts.store'), [
            'store_id' => $store->id,
            '_operation_key' => 'p289-readonly-001',
            'target_key' => 'cashbox:1',
            'amount' => '10.000',
            'value_date' => now()->toDateString(),
            'reference' => 'P289-DENY',
        ])->assertForbidden();
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);

        $english = $this->operator($store, 'en');
        $this->actingAs($english)->get($page)
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Treasury Workbench');
    }

    public function test_receipts_direct_http_is_store_scoped_even_with_an_enabled_module(): void
    {
        $owned = $this->store('P289-OWNED');
        $foreign = $this->store('P289-FOREIGN');
        $actor = $this->operator($owned, 'en');
        $this->enableTreasury($actor, $owned, [
            'treasury.receipts.read',
            'treasury.receipts.manage',
        ]);

        $this->actingAs($actor)->get(route('admin.erp.treasury.index', [
            'store_id' => $foreign->id,
            'tab' => 'receipts',
        ]))->assertForbidden();

        $foreignCashbox = $this->cashbox($foreign);
        $this->actingAs($actor)->post(route('admin.erp.treasury.receipts.store'), [
            'store_id' => $owned->id,
            '_operation_key' => 'p289-foreign-target',
            'target_key' => 'cashbox:'.$foreignCashbox->id,
            'amount' => '25.000',
            'value_date' => now()->toDateString(),
            'reference' => 'P289-FOREIGN',
        ])->assertNotFound();
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
    }

    public function test_receipt_idempotency_and_independent_checker_are_enforced_on_real_http(): void
    {
        $store = $this->store('P289-SOD');
        $maker = $this->operator($store, 'en');
        $checker = $this->operator($store, 'ar');
        $this->enableTreasury($maker, $store, [
            'treasury.receipts.read',
            'treasury.receipts.manage',
            'treasury.receipts.approve',
        ]);
        $cashbox = $this->cashbox($store);
        $data = [
            'store_id' => $store->id,
            '_operation_key' => 'p289-real-idempotent-001',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '15.250',
            'value_date' => now()->toDateString(),
            'reference' => 'P289-RECEIPT',
        ];

        foreach ([1, 2] as $attempt) {
            $this->actingAs($maker)->post(route('admin.erp.treasury.receipts.store'), $data)
                ->assertRedirect(route('admin.erp.treasury.index', [
                    'tab' => 'receipts',
                    'store_id' => $store->id,
                ]));
        }
        $this->assertDatabaseCount('enterprise_treasury_receipts', 1);
        $receiptId = (int) DB::table('enterprise_treasury_receipts')->value('id');

        $this->actingAs($maker)->post(route('admin.erp.treasury.receipts.post', $receiptId), [
            'store_id' => $store->id,
        ])->assertSessionHasErrors('approver');
        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'id' => $receiptId,
            'status' => 'draft',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.treasury.receipts.post', $receiptId), [
            'store_id' => $store->id,
        ])->assertRedirect(route('admin.erp.treasury.index', [
            'tab' => 'receipts',
            'store_id' => $store->id,
        ]));
        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'id' => $receiptId,
            'status' => 'posted',
            'posted_by' => $checker->id,
        ]);
        $this->actingAs($maker)->post(route('admin.erp.treasury.receipts.cancel', $receiptId), [
            'store_id' => $store->id,
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'id' => $receiptId,
            'status' => 'posted',
        ]);
    }

    private function store(string $code): Store
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

    private function operator(Store $store, string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $this->grantPlatformErpAccess($user);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param list<string> $capabilities */
    private function enableTreasury(User $actor, Store $store, array $capabilities): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled('treasury')) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition('treasury', ModuleState::ENABLING, $actor);
            $lifecycle->transition('treasury', ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
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
            'code' => 'P289-CASH-'.$store->id,
            'name' => 'Treasury receipts cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
