<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 T028R04: absent role-visibility preferences cannot replace RBAC authority.
 */
final class V2SidebarNoIndependentRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_absent_role_visibility_preferences_preserve_authorized_wholesale_links_only(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->delete();

        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user = $this->actor($locale);
            $user->roles()->attach($role->id);

            $this->assertTrue($user->hasPermission('catalog.view'));
            $this->assertFalse($user->hasPermission('roles.manage'));

            $keys = $this->visibleKeys($user);
            $this->assertContains('catalog_management', $keys);
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('b2c_inventory', $keys);

            $this->actingAs($user)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.b2c.dashboard', [], false))
                ->assertForbidden();
        }
    }

    public function test_absent_role_visibility_preferences_do_not_bypass_retail_store_or_channel_scope(): void
    {
        $storeA = $this->retailStore('V2-T028R04-A');
        $storeB = $this->retailStore('V2-T028R04-B');
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->delete();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user = $this->actor($locale);

            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeA,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $keys = $this->visibleKeys($user);
            $this->assertContains('b2c_inventory', $keys);
            $this->assertNotContains('b2b_products', $keys);

            $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeA], false))
                ->assertOk();

            $foreign = $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeB], false));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);

            $this->actingAs($user)
                ->get(route('admin.b2b.module', ['module' => 'products'], false))
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
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

    private function actor(string $locale): User
    {
        return User::factory()->create(['is_active' => true, 'locale' => $locale]);
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
}
