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
 * V2 P241 / #2571: Purchase Orders are a real, independently authorized tab
 * of the canonical Procurement workbench, not a second Sidebar permission.
 */
final class V2PurchaseOrdersTabAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_buyer_and_checker_discover_the_same_localized_order_tab_with_distinct_actions(): void
    {
        $store = $this->store('B2B', 'V2-P241-ORDERS');
        $this->enableProcurement();
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.orders.create',
            'admin.erp.procurement.orders.issue',
            'admin.erp.procurement.orders.cancel',
        ] as $route) {
            $this->assertTrue(Route::has($route), 'Missing real P241 route: '.$route);
        }

        $this->assertTrue($buyer->hasPermission('procurement.purchase_orders.view', $store->id));
        $this->assertTrue($buyer->hasPermission('procurement.purchase_orders.manage', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.purchase_orders.issue', $store->id));
        $this->assertTrue($checker->hasPermission('procurement.purchase_orders.view', $store->id));
        $this->assertFalse($checker->hasPermission('procurement.purchase_orders.manage', $store->id));
        $this->assertTrue($checker->hasPermission('procurement.purchase_orders.issue', $store->id));

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
                        'tab' => 'orders',
                        'store_id' => $store->id,
                    ], false))
                    ->assertOk()
                    ->assertViewIs('admin.enterprise-procurement')
                    ->assertViewHas('activeTab', 'orders')
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('enterprise_procurement.tabs.orders'))
                    ->assertViewHas('tabs', static fn (array $tabs): bool => collect($tabs)->contains('key', 'orders'))
                    ->assertViewHas('can', static fn (array $can): bool => $can['order_manage'] === $actor->hasPermission('procurement.purchase_orders.manage', $store->id)
                        && $can['order_issue'] === $actor->hasPermission('procurement.purchase_orders.issue', $store->id));
            }
        }
    }

    public function test_orders_http_rejects_unready_roleless_foreign_store_and_explicit_view_deny(): void
    {
        $own = $this->store('B2B', 'V2-P241-OWN');
        $foreignRetail = $this->store('B2C', 'V2-P241-RETAIL');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $ownUrl = route('admin.erp.procurement.index', [
            'tab' => 'orders', 'store_id' => $own->id,
        ], false);

        $this->actingAs($buyer)->get($ownUrl)->assertForbidden();

        $this->enableProcurement();
        $this->actingAs($buyer->fresh())->get($ownUrl)->assertOk();

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'orders', 'store_id' => $foreignRetail->id,
            ], false))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($ownUrl)->assertForbidden();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => Permission::query()->where('code', 'procurement.purchase_orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($buyer->fresh())->get($ownUrl)->assertForbidden();
        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers', 'store_id' => $own->id,
            ], false))
            ->assertOk()
            ->assertViewHas('tabs', static fn (array $tabs): bool => ! collect($tabs)->contains('key', 'orders'));

        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    public function test_real_order_creation_issue_and_cancel_require_separate_scoped_actors(): void
    {
        $own = $this->store('B2B', 'V2-P241-FLOW');
        $foreignRetail = $this->store('B2C', 'V2-P241-FLOW-RETAIL');
        $this->enableProcurement();
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');

        $this->actingAs($buyer)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $own->id,
            'scope_type' => 'store',
            'code' => 'V2-P241-SUP',
            'name_en' => 'P241 Supplier',
            'name_ar' => 'مورد أوامر الشراء',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'V2-P241-SUP')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $own->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'V2 P241 separate purchase order authority',
            'item_code' => 'P241-SKU',
            'description' => 'Purchase order contract test',
            'quantity' => 3,
            'unit' => 'case',
            'estimated_unit_cost' => 5.250,
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'V2 P241 separate purchase order authority')->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $own->id,
        ])->assertRedirect();

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $own->id,
            'decision' => 'approve',
            'note' => 'Separate buyer/checker, P241',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.orders.create', $requestId), [
            'store_id' => $own->id,
        ])->assertRedirect();
        $orderId = (int) DB::table('enterprise_purchase_orders')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $orderId, 'status' => 'draft', 'store_id' => $own->id,
        ]);

        $this->actingAs($buyer)->get(route('admin.erp.procurement.index', [
            'tab' => 'orders', 'store_id' => $own->id, 'order' => $orderId,
        ], false))
            ->assertOk()
            ->assertViewHas('activeTab', 'orders')
            ->assertViewHas('detail', static fn (mixed $detail): bool => $detail !== null);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.orders.issue', $orderId), [
            'store_id' => $own->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $orderId, 'status' => 'draft']);

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $orderId), [
            'store_id' => $foreignRetail->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $orderId, 'status' => 'draft']);

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $orderId), [
            'store_id' => $own->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $orderId, 'status' => 'issued']);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.orders.cancel', $orderId), [
            'store_id' => $own->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $orderId, 'status' => 'cancelled', 'store_id' => $own->id,
        ]);
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
}
