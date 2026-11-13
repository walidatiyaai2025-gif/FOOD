<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\EnterpriseModuleAdministration;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P298: Supplier Settlements is a presentation parent for the same authorized
 * AP Finance Workbench link, not a second permission/route/settlement engine.
 */
final class V2SupplierSettlementsGroupNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_supplier_settlements_group_reuses_the_only_existing_ap_route_without_duplicates(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.supplier_settlements', $registry['groups']['supplier_settlements']['label']);
        $this->assertSame('supplier_settlements', $registry['items']['erp_payables']['group']);
        $this->assertSame('receivables', $registry['items']['erp_receivables']['group']);
        $this->assertSame('finance', $registry['items']['erp_finance']['group']);
        $this->assertTrue(Route::has('admin.erp.finance.index'));

        [$actor, $storeId] = $this->authorizedWholesaleFinanceActor();
        $groups = $this->groups($actor);

        $supplier = $groups->get('supplier_settlements');
        $this->assertIsArray($supplier, 'Authorized and certified supplier AP must be discoverable.');
        $this->assertCount(1, $supplier['children']);
        $item = $supplier['children'][0];
        $this->assertSame('erp_payables', $item['key']);
        $this->assertSame('admin.erp.finance.index', $item['route']);
        $this->assertSame('payables', $item['params']['tab']);
        $this->assertSame((string) $storeId, $item['params']['store_id']);

        $occurrences = collect($groups)->flatMap(
            static fn (array $group): array => array_column($group['children'], 'key'),
        )->filter(static fn (string $key): bool => $key === 'erp_payables')->count();
        $this->assertSame(1, $occurrences);
        $this->assertFalse(collect($groups->get('receivables', ['children' => []])['children'])
            ->contains('key', 'erp_payables'));
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', $item['params']))
            ->assertOk();
    }

    public function test_arabic_and_english_keep_the_same_authorized_route_and_direction(): void
    {
        [$actor, $storeId] = $this->authorizedWholesaleFinanceActor();

        foreach ([
            'ar' => ['تسويات الموردين', 'rtl'],
            'en' => ['Supplier Settlements', 'ltr'],
        ] as $locale => [$expectedTitle, $direction]) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $group = $this->groups($actor->fresh())->get('supplier_settlements');
            $this->assertIsArray($group);
            $this->assertSame($expectedTitle, __('admin.nav_groups.supplier_settlements'));
            $this->assertSame('admin.nav_groups.supplier_settlements', $group['label']);
            $this->assertSame('admin.erp.finance.index', $group['children'][0]['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $storeId,
                    'tab' => 'payables',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_roleless_and_explicitly_denied_users_have_no_group_or_finance_access(): void
    {
        [$actor, $storeId] = $this->authorizedWholesaleFinanceActor();
        $roleless = User::factory()->create(['is_active' => true]);

        $this->assertFalse($this->groups($roleless)->has('supplier_settlements'));
        $this->actingAs($roleless)
            ->get(route('admin.erp.finance.index', ['store_id' => $storeId, 'tab' => 'payables']))
            ->assertForbidden();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($this->groups($actor->fresh())->has('supplier_settlements'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $storeId, 'tab' => 'payables']))
            ->assertForbidden();
    }

    public function test_uncertified_finance_module_hides_group_and_denies_direct_http(): void
    {
        [$actor, $storeId] = $this->authorizedWholesaleFinanceActor();
        $this->assertTrue($this->groups($actor)->has('supplier_settlements'));

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $this->assertFalse($this->groups($actor->fresh())->has('supplier_settlements'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $storeId, 'tab' => 'payables']))
            ->assertForbidden();
    }

    public function test_wholesale_finance_role_cannot_open_a_foreign_retail_store_payables_context(): void
    {
        [$actor, $storeId] = $this->authorizedWholesaleFinanceActor();

        $foreign = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-P298-FOREIGN',
            'name' => 'Foreign Retail Store',
            'is_active' => true,
        ]);

        $item = $this->groups($actor)->get('supplier_settlements')['children'][0];
        $this->assertSame((string) $storeId, $item['params']['store_id']);
        $response = $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $foreign->id,
                'tab' => 'payables',
            ]));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    /** @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function groups(User $user): \Illuminate\Support\Collection
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($user))->keyBy('key');
    }

    /** @return array{User,int} */
    private function authorizedWholesaleFinanceActor(): array
    {
        // Separate privileged module bootstrap from the actual operator so that
        // a permission DENY is tested without SUPER_ADMIN's unconditional grant.
        $bootstrap = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $bootstrap->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id,
        );
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(
            Role::query()->whereIn('code', ['B2B_ADMIN', 'FINANCE'])->pluck('id')->all(),
        );

        $storeId = app(WholesalePrincipal::class)->storeId();
        $store = Store::query()->findOrFail($storeId);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($storeId);
        $this->assertNotNull($scope);

        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $bootstrap);
        $manager = app(ModuleEntitlementManager::class);
        $manager->set(FinanceFoundationService::MODULE, EntitlementScope::platform(), true, null, $bootstrap);
        $manager->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            ['finance.foundation.read', 'finance.ap.read', 'finance.ar.read'],
            $bootstrap,
        );
        $this->assertTrue($actor->hasPermission('finance.view', $storeId));

        return [$actor, $storeId];
    }
}
