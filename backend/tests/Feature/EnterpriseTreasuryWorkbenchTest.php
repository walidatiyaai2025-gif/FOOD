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
use App\Models\EnterpriseCashbox;
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

final class EnterpriseTreasuryWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_workbench_fails_closed_while_treasury_module_is_disabled(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-OFF', 'treasury-off@example.test');

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id]))
            ->assertNotFound();
    }

    public function test_authorized_operator_can_open_arabic_workbench_and_create_bounded_receipt_draft(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-AR', 'treasury-ar@example.test', 'ar');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $this->enableTreasury($user, $context);
        $cashbox = $this->cashbox($store);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'receipts',
            ]))
            ->assertOk()
            ->assertSee('مركز عمل الخزينة')
            ->assertSee($cashbox->code)
            ->assertSee(route('admin.treasury.master.index', ['store_id' => $store->id]));

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'ui-receipt-http-001',
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '15.250',
                'value_date' => '2026-10-16',
                'reference' => 'UI-RCPT-001',
                'description' => 'Treasury workbench proof',
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'receipts',
                'store_id' => $store->id,
            ]));

        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'store_id' => $store->id,
            'target_type' => 'cashbox',
            'target_id' => $cashbox->id,
            'amount' => '15.250',
            'reference' => 'UI-RCPT-001',
            'status' => 'draft',
        ]);
    }

    public function test_english_workbench_uses_business_readable_bank_labels_and_cross_store_scope_is_denied(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-EN', 'treasury-en@example.test', 'en');
        [, $otherStore] = $this->operator('ERP-TREASURY-OTHER', 'treasury-other@example.test', 'en');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $this->enableTreasury($user, $context);
        $bank = $this->bankAccount($store);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'cheques',
            ]))
            ->assertOk()
            ->assertSee('Treasury Workbench')
            ->assertSee($bank->code)
            ->assertSee('****1234')
            ->assertDontSee('name="bank_account_id" type="text"', false);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $otherStore->id,
                'tab' => 'receipts',
            ]))
            ->assertForbidden();
    }

    public function test_full_treasury_workbench_route_surface_is_registered(): void
    {
        foreach ([
            'admin.erp.treasury.index',
            'admin.erp.treasury.receipts.store',
            'admin.erp.treasury.receipts.post',
            'admin.erp.treasury.receipts.cancel',
            'admin.erp.treasury.payments.store',
            'admin.erp.treasury.payments.post',
            'admin.erp.treasury.payments.cancel',
            'admin.erp.treasury.cheques.store',
            'admin.erp.treasury.cheques.transition',
            'admin.erp.treasury.reconciliation.store',
            'admin.erp.treasury.reconciliation.matches.store',
            'admin.erp.treasury.reconciliation.close',
            'admin.erp.treasury.cash-closing.store',
            'admin.erp.treasury.cash-closing.close',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing Treasury UI route: '.$name);
        }
    }

    /** @return array{User,Store} */
    private function operator(string $storeCode, string $email, string $locale = 'ar'): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $user = User::factory()->create([
            'email' => $email,
            'locale' => $locale,
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
            ],
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
            'code' => 'UI-CASH-'.$store->id,
            'name' => 'Front Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function bankAccount(Store $store): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'UI-BANK-'.$store->id,
            'name' => 'Operating Bank',
            'bank_name' => 'Treasury Bank',
            'account_name' => 'FOODEX Treasury',
            'account_reference' => 'KW00TEST1234',
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
