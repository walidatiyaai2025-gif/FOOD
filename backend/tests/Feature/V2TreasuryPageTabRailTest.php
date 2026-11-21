<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
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

final class V2TreasuryPageTabRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Signed synthetic workflow proof is scoped to test fixtures, not ERP runtime certification.
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_authorized_page_tab_rail_stays_single_row_native_and_non_mutating_in_arabic_and_english(): void
    {
        [$actor, $store] = $this->operator('V2-4007-RAIL', 'v2-4007-rail@example.test');
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $this->enableTreasury($actor, $context);
        $before = $this->mutationSnapshot();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => 'receipts',
                ]))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee(__('enterprise_treasury.tabs.receipts'))
                ->assertSee('class="foodex-tabs foodex-tabs--horizontal"', false);

            $html = (string) $response->getContent();
            $this->assertSame(1, preg_match_all('/<nav\b(?=[^>]*\bclass="foodex-tabs foodex-tabs--horizontal")[^>]*>.*?<\/nav>/s', $html, $navigation), $direction);
            $nav = $navigation[0][0];
            $this->assertSame(1, substr_count($nav, 'aria-current="page"'), $direction);
            $this->assertStringNotContainsString('role="tab"', $nav, $direction);
            $this->assertStringContainsString('.foodex-tabs.foodex-tabs--horizontal', $html);
            $this->assertStringContainsString('flex-wrap:nowrap;overflow-x:auto;', $html);
            $this->assertStringContainsString('.foodex-tabs.foodex-tabs--horizontal > a:focus-visible', $html);
            $this->assertStringContainsString('--foodex-touch-target:44px', $html);
            $this->assertStringContainsString('store_id='.$store->id, $nav);
            $this->assertStringContainsString('tab=receipts', $nav);
            $this->assertSame($before, $this->mutationSnapshot(), $direction);
        }
    }

    public function test_page_tab_links_do_not_bypass_selected_store_authorization(): void
    {
        [$actor, $storeA] = $this->operator('V2-4007-STORE-A', 'v2-4007-store-a@example.test');
        $storeB = $this->store('V2-4007-STORE-B');
        $context = app(TenantContextResolver::class)->retail($actor, $storeA->id);
        $this->enableTreasury($actor, $context);
        $before = $this->mutationSnapshot();

        $this->actingAs($actor)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $storeB->id,
                'tab' => 'payments',
            ]))
            ->assertForbidden();

        $this->assertSame($before, $this->mutationSnapshot());
    }

    /** @return array{User,Store} */
    private function operator(string $storeCode, string $email): array
    {
        $store = $this->store($storeCode);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $actor = User::factory()->create([
            'email' => $email,
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store];
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
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

    /** @return array<string, int> */
    private function mutationSnapshot(): array
    {
        return [
            'audit' => DB::table('audit_logs')->count(),
            'roles' => DB::table('role_user')->count(),
            'store_roles' => DB::table('user_store_roles')->count(),
            'overrides' => DB::table('user_permission_overrides')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
            'payments' => DB::table('enterprise_treasury_payments')->count(),
            'cheques' => DB::table('enterprise_treasury_cheques')->count(),
            'reconciliations' => DB::table('enterprise_treasury_reconciliations')->count(),
            'cash_closings' => DB::table('enterprise_treasury_cash_closings')->count(),
        ];
    }
}
