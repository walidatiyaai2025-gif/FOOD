<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P240 / #2570: existing Purchase Requests tab is a real scoped procurement
 * destination, distinct from a role's generic supplier permission.
 */
final class V2PurchaseRequestsTabAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_buyer_and_checker_open_real_purchase_requests_in_arabic_and_english(): void
    {
        $store = $this->store('B2B', 'V2-P240-WHOLESALE');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $this->enableProcurement();

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.requests.store',
            'admin.erp.procurement.requests.submit',
            'admin.erp.procurement.requests.decision',
            'admin.erp.procurement.requests.cancel',
        ] as $route) {
            $this->assertTrue(Route::has($route), 'Missing real P240 route: '.$route);
        }

        $this->assertTrue($buyer->hasPermission('procurement.purchase_requests.view', $store->id));
        $this->assertTrue($buyer->hasPermission('procurement.purchase_requests.manage', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.purchase_requests.approve', $store->id));
        $this->assertTrue($checker->hasPermission('procurement.purchase_requests.view', $store->id));
        $this->assertFalse($checker->hasPermission('procurement.purchase_requests.manage', $store->id));
        $this->assertTrue($checker->hasPermission('procurement.purchase_requests.approve', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ([$buyer, $checker] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->forgetInstance(AdminNavigation::class);

                $navigation = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->keyBy('key');
                $this->assertTrue($navigation->has('erp_procurement'));
                $this->assertSame('admin.erp.procurement.index', $navigation['erp_procurement']['route']);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'tab' => 'requests',
                        'store_id' => $store->id,
                    ], false))
                    ->assertOk()
                    ->assertViewHas('activeTab', 'requests')
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('enterprise_procurement.tabs.requests'));
            }
        }
    }

    public function test_uncertified_module_and_unrelated_identities_never_gain_the_purchase_request_tab(): void
    {
        $store = $this->store('B2B', 'V2-P240-DISABLED');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $this->assertFalse($this->hasProcurementNavigation($buyer));

        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'requests', 'store_id' => $store->id,
            ], false))
            ->assertForbidden();

        $this->enableProcurement();

        foreach ([$this->actor('B2C_DRIVER'), User::factory()->create(['is_active' => true])] as $outsider) {
            $this->assertFalse($this->hasProcurementNavigation($outsider));
            $this->actingAs($outsider)
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'requests', 'store_id' => $store->id,
                ], false))
                ->assertForbidden();
        }
    }

    public function test_explicit_request_view_deny_and_foreign_retail_channel_are_enforced_by_http(): void
    {
        $wholesale = $this->store('B2B', 'V2-P240-OWNED');
        $retail = $this->store('B2C', 'V2-P240-FOREIGN-RETAIL');
        $this->enableProcurement();
        $buyer = $this->actor('PROCUREMENT_BUYER');

        // A Wholesale procurement buyer cannot select an unrelated Retail store.
        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'requests', 'store_id' => $retail->id,
            ], false))
            ->assertForbidden();

        $permissionId = Permission::query()
            ->where('code', 'procurement.purchase_requests.view')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => $permissionId,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        // The actor can still use Suppliers through the existing Procurement
        // entry, but the denied Purchase Requests tab cannot be opened.
        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers', 'store_id' => $wholesale->id,
            ], false))
            ->assertOk()
            ->assertViewHas('tabs', static fn (array $tabs): bool => ! collect($tabs)->contains('key', 'requests'));

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'requests', 'store_id' => $wholesale->id,
            ], false))
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_purchase_requests', 0);
    }

    private function store(string $channel, string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function enableProcurement(): void
    {
        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    private function hasProcurementNavigation(User $actor): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'erp_procurement');
    }
}
