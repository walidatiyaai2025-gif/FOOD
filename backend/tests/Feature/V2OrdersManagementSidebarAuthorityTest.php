<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P207: the Orders Management Sidebar link is a projection of real
 * server-side authority, never an alternative permission or a dummy screen.
 */
final class V2OrdersManagementSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_admin_has_one_canonical_orders_destination_and_cannot_enter_retail(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'p207-wholesale@example.test');
        app(WholesalePrincipal::class)->storeId();

        $item = $this->ordersNavigationItem($actor);
        $this->assertNotNull($item);
        $this->assertSame('admin.operations.orders.index', $item['route']);
        $this->assertSame('admin.order_management', $item['label']);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertOk()
            ->assertViewIs('admin.order-operations')
            ->assertViewHas('selectedChannel', 'b2b')
            ->assertSee('data-order-grid-filter-controls', false);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'all']))
            ->assertForbidden();
    }

    public function test_retail_store_admin_can_only_use_own_store_orders_and_never_wholesale(): void
    {
        $storeId = $this->retailStore('P207-OWN-STORE');
        $otherStoreId = $this->retailStore('P207-OTHER-STORE');
        $actor = $this->retailActor($storeId, 'p207-retail@example.test');

        $item = $this->ordersNavigationItem($actor);
        $this->assertNotNull($item);
        $this->assertSame('admin.operations.orders.index', $item['route']);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertOk()
            ->assertViewHas('selectedChannel', 'b2c')
            ->assertViewHas('selectedStoreId', $storeId)
            ->assertSee('data-order-grid-filter-controls', false);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
                'store_id' => $otherStoreId,
            ]))
            ->assertNotFound();

        foreach (['b2b', 'all'] as $forbiddenChannel) {
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', [
                    'channel' => $forbiddenChannel,
                ]))
                ->assertForbidden();
        }
    }

    public function test_roleless_and_inactive_actors_have_no_orders_sidebar_or_direct_http_access(): void
    {
        $roleless = User::query()->create([
            'name' => 'Roleless P207',
            'email' => 'p207-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $inactive = $this->globalActor('B2B_ADMIN', 'p207-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $this->assertNull($this->ordersNavigationItem($actor));
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2b',
                ]))
                ->assertForbidden();
        }
    }

    public function test_cosmetic_sidebar_toggle_cannot_revoke_real_orders_http_permission(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'p207-layout@example.test');
        app(WholesalePrincipal::class)->storeId();

        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'order_operations',
            'group_key' => 'operations',
            'parent_key' => null,
            'label_ar' => null,
            'label_en' => null,
            'sort_order' => 20,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->ordersNavigationItem($actor));
        $this->assertTrue($actor->hasPermission('orders.view'));

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2b',
            ]))
            ->assertOk()
            ->assertViewIs('admin.order-operations');

        // Hiding a menu entry is cosmetic and must not independently grant the
        // opposite channel or bypass the canonical controller scope.
        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', [
                'channel' => 'b2c',
            ]))
            ->assertForbidden();
    }

    public function test_existing_orders_screen_is_bilingual_direction_aware_and_uses_one_live_route(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'p207-locale@example.test');
        app(WholesalePrincipal::class)->storeId();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotSame(
                'admin.order_management',
                __('admin.order_management', [], $locale),
            );
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2b',
                ]))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertViewIs('admin.order-operations');
        }
    }

    /** @return array<string,mixed>|null */
    private function ordersNavigationItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                if (($item['key'] ?? null) === 'order_operations') {
                    return $item;
                }
            }
        }

        return null;
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId, string $email): User
    {
        $actor = User::query()->create([
            'name' => 'P207 Retail Operator',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
