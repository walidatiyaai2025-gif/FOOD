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
 * V2 P035: role-specific Sidebar preferences do not authorize HTTP requests.
 * This supplements the global-layout assertions in P040 and the canonical
 * navigation/metadata projection contract in P382.
 */
final class V2SidebarRoleVisibilityHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_hiding_a_role_sidebar_link_does_not_revoke_authorized_wholesale_http_access(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = $this->globalActor($role);
        $permissionsBefore = $role->permissions()->pluck('permissions.id')->all();

        $this->assertTrue($actor->hasPermission('catalog.view'));
        $this->assertContains('catalog_management', $this->visibleKeys($actor));

        $this->setVisibility($role, 'catalog_management', false);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains('catalog_management', $this->visibleKeys($actor));
            $this->assertTrue($actor->hasPermission('catalog.view'));

            $this->actingAs($actor)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->getKey(),
            'item_key' => 'catalog_management',
            'is_visible' => false,
        ]);
        $this->assertEqualsCanonicalizing(
            $permissionsBefore,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_hidden_retail_role_link_preserves_own_store_route_but_not_foreign_store_or_wholesale(): void
    {
        $allowedStore = $this->retailStore('V2-P035-ALLOWED');
        $foreignStore = $this->retailStore('V2-P035-FOREIGN');
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $allowedStore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains('b2c_inventory', $this->visibleKeys($actor));
        $this->setVisibility($role, 'b2c_inventory', false);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains('b2c_inventory', $this->visibleKeys($actor));
            $this->assertTrue($actor->hasPermission('inventory.view', $allowedStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $foreignStore));

            $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $allowedStore,
            ], false))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $foreign = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreignStore,
            ], false));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);

            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $allowedStore,
        ]);
    }

    public function test_forced_visible_role_link_cannot_overrule_user_deny_or_grant_http_access(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = $this->globalActor($role);
        $permissionsBefore = $role->permissions()->pluck('permissions.id')->all();

        $this->assertContains('catalog_management', $this->visibleKeys($actor));
        $this->setVisibility($role, 'catalog_management', true);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'catalog.view')->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertFalse($actor->hasPermission('catalog.view'));
            $this->assertNotContains('catalog_management', $this->visibleKeys($actor));
            $this->actingAs($actor)
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->getKey(),
            'item_key' => 'catalog_management',
            'is_visible' => true,
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'effect' => 'deny',
            'scope_key' => 'global',
        ]);
        $this->assertEqualsCanonicalizing(
            $permissionsBefore,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    private function globalActor(Role $role): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->getKey());

        return $actor->fresh();
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

    private function setVisibility(Role $role, string $itemKey, bool $visible): void
    {
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->getKey(),
            'item_key' => $itemKey,
            'is_visible' => $visible,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
