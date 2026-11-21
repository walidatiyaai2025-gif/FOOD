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
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P297 is the existing Treasury reconciliation tab, not a second Sidebar
 * route. Test the real HTTP boundary and prevent unusable mutation controls.
 */
final class V2BankReconciliationSidebarContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Synthetic workflow proof is scoped to positive test fixtures only.
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);

        // Test-only version certification; never a production readiness grant.
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_disabled_treasury_module_denies_bank_reconciliation_route(): void
    {
        [$user, $store] = $this->operator('P297-DISABLED');

        $this->actingAs($user)
            ->get($this->url($store))
            ->assertForbidden();
    }

    public function test_read_entitlement_without_manage_capability_keeps_tab_but_hides_dead_create_action(): void
    {
        [$user, $store, $context] = $this->operator('P297-READ');
        $bank = $this->bank($store);
        $this->enableTreasury($user, $context, ['treasury.reconciliation.read']);

        $this->actingAs($user)
            ->get($this->url($store))
            ->assertOk()
            ->assertSee($bank->code)
            ->assertSee('data-erp-treasury-workbench="true"', false)
            ->assertDontSee(route('admin.erp.treasury.reconciliation.store'), false);

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.reconciliation.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'p297-denied-create',
                'bank_account_id' => $bank->id,
                'period_from' => now()->subDays(2)->toDateString(),
                'period_to' => now()->toDateString(),
                'statement_receipts_total' => '0.000',
                'statement_payments_total' => '0.000',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_treasury_reconciliations', 0);
    }

    public function test_draft_creation_is_idempotent_and_maker_cannot_see_own_close_action(): void
    {
        [$maker, $store, $context] = $this->operator('P297-MAKER');
        $bank = $this->bank($store);
        $this->enableTreasury($maker, $context, [
            'treasury.reconciliation.read',
            'treasury.reconciliation.manage',
            'treasury.reconciliation.approve',
        ]);

        $payload = [
            'store_id' => $store->id,
            '_operation_key' => 'p297-bank-statement-001',
            'bank_account_id' => $bank->id,
            'period_from' => now()->subDays(2)->toDateString(),
            'period_to' => now()->toDateString(),
            'statement_receipts_total' => '0.000',
            'statement_payments_total' => '0.000',
        ];

        $expectedRedirect = route('admin.erp.treasury.index', [
            'tab' => 'reconciliation',
            'store_id' => $store->id,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($maker)
                ->post(route('admin.erp.treasury.reconciliation.store'), $payload)
                ->assertRedirect($expectedRedirect);
        }

        $this->assertDatabaseCount('enterprise_treasury_reconciliations', 1);
        $row = DB::table('enterprise_treasury_reconciliations')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $maker->id, (int) $row->requested_by);
        $closeUrl = route('admin.erp.treasury.reconciliation.close', $row->id);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();

            $this->actingAs($maker->fresh())
                ->get($this->url($store))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($bank->code)
                ->assertSee(route('admin.erp.treasury.reconciliation.store'), false)
                ->assertDontSee($closeUrl, false);
        }

        // An independent checker with identical store and finance authority
        // may see the close action; the service still checks approval & totals.
        $checker = $this->userForStore($store, 'p297-checker@example.test');
        $this->actingAs($checker)
            ->get($this->url($store))
            ->assertOk()
            ->assertSee($closeUrl, false);

        // Source-of-truth SoD also rejects the maker's direct POST.
        $this->actingAs($maker->fresh())
            ->post(route('admin.erp.treasury.reconciliation.close', $row->id), [
                'store_id' => $store->id,
            ])
            ->assertSessionHasErrors('approver');

        $this->assertDatabaseHas('enterprise_treasury_reconciliations', [
            'id' => $row->id,
            'status' => 'draft',
        ]);
    }

    public function test_cross_store_and_explicit_finance_view_deny_block_direct_reconciliation_http(): void
    {
        [$user, $store, $context] = $this->operator('P297-SCOPE');
        [, $otherStore] = $this->operator('P297-OTHER');
        $this->enableTreasury($user, $context, ['treasury.reconciliation.read']);

        $this->actingAs($user)
            ->get($this->url($otherStore))
            ->assertForbidden();

        $this->actingAs($user)
            ->get($this->url($store))
            ->assertOk();

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($user->fresh())
            ->get($this->url($store))
            ->assertForbidden();
    }

    private function url(Store $store): string
    {
        return route('admin.erp.treasury.index', [
            'store_id' => $store->id,
            'tab' => 'reconciliation',
        ]);
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

        $user = $this->userForStore($store, strtolower($code).'@example.test');

        return [$user, $store, app(TenantContextResolver::class)->retail($user, $store->id)];
    }

    private function userForStore(Store $store, string $email): User
    {
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
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

    private function bank(Store $store): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'P297-BANK-'.$store->id,
            'name' => 'Operating Bank',
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

    /** @param list<string> $capabilities */
    private function enableTreasury(User $actor, ResolvedTenantContext $context, array $capabilities): void
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
            $capabilities,
            $actor,
        );
    }
}
