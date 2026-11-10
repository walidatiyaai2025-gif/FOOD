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
 * P008: removing a Sidebar destination must never be the authorization gate:
 * both the rendered projection and direct HTTP route independently deny.
 */
final class V2HiddenLinkDirectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_admin_without_security_grant_has_no_role_editor_link_or_direct_route(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();

            $this->assertNotContains('sidebar_role_access', $this->rootKeys($user));
            $this->assertNotContains('sidebar_layout', $this->rootKeys($user));

            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.security.index'))
                ->assertForbidden();
        }
    }

    public function test_retail_permission_deny_hides_orders_but_direct_b2c_url_still_returns_403(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-P008-RETAIL',
            'name' => 'P008 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($actor->fresh()->hasPermission('orders.view', $storeId));
        $this->assertContains('order_operations', $this->rootKeys($actor->fresh()));

        $this->actingAs($actor->fresh())
            ->get('/admin/b2c/orders?store_id='.$storeId)
            ->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();

            $this->assertFalse($user->hasPermission('orders.view', $storeId));
            $this->assertNotContains('order_operations', $this->rootKeys($user));

            $this->actingAs($user)
                ->get('/admin/b2c/orders?store_id='.$storeId)
                ->assertForbidden();
            $this->actingAs($user)
                ->get('/admin/b2b/orders')
                ->assertForbidden();
        }

        // A user-level DENY is not a mutation of the shared Retail role.
        $this->assertTrue($role->permissions()->where('code', 'orders.view')->exists());
    }

    public function test_super_admin_retains_the_authorized_role_editor_destination_and_route(): void
    {
        $owner = $this->globalActor('SUPER_ADMIN');

        $this->assertContains('sidebar_role_access', $this->rootKeys($owner));
        $this->actingAs($owner)
            ->get(route('admin.sidebar-access.roles'))
            ->assertOk();
    }

    /** @return list<string> */
    private function rootKeys(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail()->id);

        return $actor->fresh();
    }
}
