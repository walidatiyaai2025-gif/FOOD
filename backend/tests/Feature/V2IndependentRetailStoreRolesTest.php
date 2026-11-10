<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P119: Retail rights belong to a store and role assignment, not to a
 * cross-store user identity, a Sidebar link, or an arbitrary URL parameter.
 */
final class V2IndependentRetailStoreRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_independent_retail_staff_have_no_global_or_foreign_store_permission_even_with_same_role(): void
    {
        $one = $this->retailStore('V2-P119-ONE');
        $two = $this->retailStore('V2-P119-TWO');
        $outsider = $this->retailStore('V2-P119-OUTSIDER');

        $alice = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $bob = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assign($alice, $one, 'RETAIL_INVENTORY');
        $this->assign($bob, $two, 'RETAIL_INVENTORY');

        foreach ([[$alice, $one, $two], [$bob, $two, $one]] as [$actor, $allowedStore, $otherStore]) {
            $this->assertFalse($actor->hasPermission('inventory.view'));
            $this->assertTrue($actor->hasPermission('inventory.view', $allowedStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $otherStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $outsider));
            $this->assertContains('inventory.view', $actor->effectivePermissionCodes($allowedStore));
            $this->assertNotContains('inventory.view', $actor->effectivePermissionCodes($otherStore));

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $allowedStore]))
                ->assertOk()
                ->assertViewHas('storeId', $allowedStore)
                ->assertViewHas('supportAccess', false);

            foreach ([$otherStore, $outsider] as $forbiddenStore) {
                $this->actingAs($actor)
                    ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $forbiddenStore]))
                    ->assertNotFound();

                // A forged support-access flag must never lift Retail-store tenancy.
                $this->actingAs($actor)
                    ->get(route('admin.b2c.module', [
                        'module' => 'inventory',
                        'store_id' => $forbiddenStore,
                        'support_access' => 1,
                    ]))->assertNotFound();
            }

            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory']))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.security.index'))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('user_permission_overrides', 0);
    }

    public function test_distinct_store_roles_never_combine_order_and_inventory_powers_across_stores_or_locales(): void
    {
        $ops = $this->retailStore('V2-P119-OPERATIONS');
        $stock = $this->retailStore('V2-P119-INVENTORY');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assign($actor, $ops, 'RETAIL_OPERATIONS');
        $this->assign($actor, $stock, 'RETAIL_INVENTORY');

        $this->assertTrue($actor->hasPermission('orders.view', $ops));
        $this->assertFalse($actor->hasPermission('inventory.view', $ops));
        $this->assertTrue($actor->hasPermission('inventory.view', $stock));
        $this->assertFalse($actor->hasPermission('orders.view', $stock));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $navigation = app(AdminNavigation::class);
            $this->assertSame(['b2c'], array_keys($navigation->for($actor)));

            $nodes = collect($navigation->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertSame((string) $ops, $nodes->get('b2c_reports')['params']['store_id']);
            $this->assertSame((string) $stock, $nodes->get('b2c_inventory')['params']['store_id']);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $ops]))
                ->assertOk()
                ->assertViewHas('storeId', $ops)
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $stock]))
                ->assertOk()
                ->assertViewHas('storeId', $stock);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $ops]))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $stock]))
                ->assertNotFound(); // Fail-closed store-scoped order concealment.
        }
    }

    public function test_foreign_store_allow_override_without_store_role_cannot_mint_tenant_access(): void
    {
        $owned = $this->retailStore('V2-P119-GRANTED');
        $foreign = $this->retailStore('V2-P119-UNASSIGNED');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assign($actor, $owned, 'RETAIL_INVENTORY');
        $permission = Permission::query()->where('code', 'inventory.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$foreign,
            'store_id' => $foreign,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('inventory.view', $owned));
        $this->assertFalse($actor->hasPermission('inventory.view', $foreign));
        $this->assertNotContains('inventory.view', $actor->effectivePermissionCodes($foreign));

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $foreign]))
            ->assertNotFound();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assign(User $user, int $storeId, string $roleCode): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
