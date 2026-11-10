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

final class V2MultiRoleConflictBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_conflicting_wholesale_job_roles_union_only_explicit_permissions_in_arabic_and_english(): void
    {
        $user = $this->globalUser(['INVENTORY', 'CUSTOMER_SUPPORT']);

        $this->assertTrue($user->hasPermission('inventory.view'));
        $this->assertTrue($user->hasPermission('support.view'));
        $this->assertFalse($user->hasPermission('system.update'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $navigation = app(AdminNavigation::class);
            $this->assertSame(['b2b'], array_keys($navigation->for($user)));

            $keys = $this->keys($user);
            $this->assertContains('b2b_products', $keys);
            $this->assertContains('customer_360', $keys);
            $this->assertNotContains('b2c_inventory', $keys);
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('platform_owner_modules', $keys);
            $this->assertSame(count($keys), count(array_unique($keys)), 'Conflicting roles created duplicate navigation destinations.');

            $this->actingAs($user)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($user)->get(route('admin.security.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.system-update.index'))->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'inventory']))
                ->assertForbidden();
        }
    }

    public function test_mixed_wholesale_and_retail_roles_preserve_store_authority_even_when_navigation_shows_both_channels(): void
    {
        $retailStore = $this->retailStore('V2-2794-OWNED');
        $foreignStore = $this->retailStore('V2-2794-FOREIGN');
        $user = $this->globalUser(['B2B_ADMIN']);
        $this->assignRetailRole($user, $retailStore, 'RETAIL_INVENTORY');

        $navigation = app(AdminNavigation::class);
        $this->assertSame(['b2b', 'b2c'], array_keys($navigation->for($user)));

        $items = collect($navigation->groupsFor($user))
            ->flatMap(fn (array $group): array => $group['children'])
            ->keyBy('key');

        $this->assertTrue($items->has('b2b_products'));
        $this->assertTrue($items->has('b2c_inventory'));
        $this->assertSame(
            ['module' => 'inventory', 'store_id' => (string) $retailStore],
            $items->get('b2c_inventory')['params'],
        );
        $this->assertFalse($items->has('platform_owner_modules'));

        $this->actingAs($user)
            ->get(route('admin.b2b.module', ['module' => 'products']))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $retailStore]))
            ->assertOk()
            ->assertViewHas('storeId', $retailStore);
        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $foreignStore]))
            ->assertNotFound();

        $this->actingAs($user)->get(route('admin.security.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.system-update.index'))->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')
            ->where('user_id', $user->getKey())
            ->where('event', 'user.roles_changed')
            ->count());
    }

    public function test_explicit_catalog_deny_outweighs_two_granting_roles_for_sidebar_and_http(): void
    {
        $user = $this->globalUser(['INVENTORY', 'B2B_ADMIN']);
        $catalog = Permission::query()->where('code', 'catalog.view')->firstOrFail();

        $this->assertTrue($user->hasPermission('catalog.view'));
        $this->assertTrue($user->hasPermission('inventory.view'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => $catalog->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
        $user = $user->fresh();

        $this->assertFalse($user->hasPermission('catalog.view'));
        $this->assertTrue($user->hasPermission('inventory.view'));

        $keys = $this->keys($user);
        $this->assertNotContains('catalog_management', $keys);
        $this->assertNotContains('b2b_products', $keys);
        $this->assertContains('b2b_dashboard', $keys);

        $this->actingAs($user)->get(route('admin.catalog.index'))->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.b2b.module', ['module' => 'products']))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.b2b.module', ['module' => 'inventory']))
            ->assertOk();
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $user->getKey(),
            'permission_id' => $catalog->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    public function test_inactive_privileged_role_does_not_gain_a_third_permission_when_combined_with_active_roles(): void
    {
        $user = $this->globalUser(['INVENTORY', 'CUSTOMER_SUPPORT']);
        $inactiveRole = Role::query()->create([
            'code' => 'V2_INACTIVE_SECURITY_ROLE',
            'name' => 'Inactive test security role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => false,
        ]);
        $inactiveRole->permissions()->attach(
            Permission::query()->where('code', 'system.update')->firstOrFail(),
        );
        $user->roles()->attach($inactiveRole);

        $this->assertFalse($user->fresh()->hasPermission('system.update'));
        $this->assertNotContains('platform_owner_modules', $this->keys($user));
        $this->actingAs($user)->get(route('admin.system-update.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.b2b.dashboard'))->assertOk();
    }

    /** @return list<string> */
    private function keys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    /** @param list<string> $roleCodes */
    private function globalUser(array $roleCodes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ($roleCodes as $code) {
            $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());
        }

        return $user;
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

    private function assignRetailRole(User $user, int $storeId, string $roleCode): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
