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
 * P242 / #2572: receiving is the real Procurement workbench Receipts tab.
 * Navigation, selected-store authorization and independent manage/post
 * decisions are enforced by the canonical HTTP/service boundaries.
 */
final class V2GoodsReceiptsWorkbenchAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_buyer_and_manager_see_real_scoped_receipts_tab_with_distinct_actions_in_both_locales(): void
    {
        $store = $this->store('B2B', 'P242-RECEIVE-WHOLESALE');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        $this->enableProcurement();

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.receipts.store',
            'admin.erp.procurement.receipts.post',
            'admin.erp.procurement.receipts.cancel',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), 'P242 real receipt route missing: '.$routeName);
        }

        $this->assertTrue($buyer->hasPermission('procurement.goods_receipts.view', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.goods_receipts.manage', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.goods_receipts.post', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.view', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.manage', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.post', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);

            foreach ([$buyer, $manager] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->forgetInstance(AdminNavigation::class);
                $sidebar = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->keyBy('key');

                $this->assertTrue($sidebar->has('erp_procurement'));
                $this->assertSame('admin.erp.procurement.index', $sidebar['erp_procurement']['route']);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'tab' => 'receipts', 'store_id' => $store->id,
                    ], false))
                    ->assertOk()
                    ->assertViewHas('activeTab', 'receipts')
                    ->assertViewHas('can', static fn (array $abilities): bool =>
                        $abilities['receipt_manage'] === $actor->hasPermission('procurement.goods_receipts.manage', $store->id)
                        && $abilities['receipt_post'] === $actor->hasPermission('procurement.goods_receipts.post', $store->id)
                    )
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('enterprise_procurement.tabs.receipts'));
            }
        }
    }

    public function test_disabled_or_uncertified_procurement_and_unrelated_roles_cannot_open_receiving(): void
    {
        $store = $this->store('B2B', 'P242-DISABLED');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $this->assertFalse($this->hasProcurementLink($buyer));

        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $store->id,
            ], false))
            ->assertForbidden();

        $this->enableProcurement();

        foreach ([$this->actor('PROCUREMENT_APPROVER'), $this->actor('B2C_DRIVER'), User::factory()->create(['is_active' => true])] as $outsider) {
            $this->actingAs($outsider)
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'receipts', 'store_id' => $store->id,
                ], false))
                ->assertForbidden();
        }
    }

    public function test_direct_receipt_write_and_post_reject_view_only_buyer_before_record_lookup(): void
    {
        $store = $this->store('B2B', 'P242-MUTATION');
        $this->enableProcurement();
        $buyer = $this->actor('PROCUREMENT_BUYER');

        $payload = [
            'store_id' => $store->id,
            'warehouse_id' => 999999,
            'lines' => [[
                'purchase_order_line_id' => 999999,
                'quantity' => 1,
            ]],
        ];

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.store', 999999, false), $payload)
            ->assertForbidden();
        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.post', 999999, false), ['store_id' => $store->id])
            ->assertForbidden();
        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.cancel', 999999, false), ['store_id' => $store->id])
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
    }

    public function test_cross_channel_store_and_multirole_explicit_view_deny_fail_closed(): void
    {
        $ownStore = $this->store('B2B', 'P242-OWN');
        $retailStore = $this->store('B2C', 'P242-FOREIGN');
        $this->enableProcurement();

        $buyer = $this->actor('PROCUREMENT_BUYER');
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail());

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $retailStore->id,
            ], false))
            ->assertForbidden();

        $permission = Permission::query()->where('code', 'procurement.goods_receipts.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers', 'store_id' => $ownStore->id,
            ], false))
            ->assertOk()
            ->assertViewHas('tabs', static fn (array $tabs): bool => ! collect($tabs)->contains('key', 'receipts'));

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $ownStore->id,
            ], false))
            ->assertForbidden();
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

    private function hasProcurementLink(User $actor): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'erp_procurement');
    }
}
