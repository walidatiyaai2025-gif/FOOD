<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P052: Customer and field-operation account classifications do not create
 * Dashboard administrative roles, grants, or an implicit cross-channel scope.
 */
final class V2NonAdminAccountBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_and_b2c_customer_profiles_without_admin_roles_are_denied_admin_http_in_both_locales(): void
    {
        foreach (['b2b', 'b2c'] as $type) {
            $user = User::factory()->create([
                'is_active' => true,
                'is_platform_customer' => true,
                'locale' => 'en',
            ]);
            Customer::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'name' => 'V2 P052 '.$type.' Customer',
                'email' => 'v2-p052-'.$type.'-'.$user->id.'@example.test',
            ]);

            $this->assertSame(0, $user->roles()->count());
            $this->assertSame(0, $user->storeRoleAssignments()->count());

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $user->forceFill(['locale' => $locale])->save();
                $user = $user->fresh();

                $this->assertFalse($user->hasRole('SUPER_ADMIN'));
                $this->assertFalse($user->hasPermission('users.view'));
                $this->assertFalse($user->hasPermission('roles.manage'));
                $this->assertFalse($user->hasPermission('inventory.view'));
                $this->assertSame([], app(AdminNavigation::class)->for($user));

                $this->actingAs($user)
                    ->get(route('admin.security.index'))
                    ->assertForbidden();
                $this->actingAs($user)
                    ->get(route('admin.b2b.module', ['module' => 'inventory']))
                    ->assertForbidden();
            }
        }
    }

    public function test_driver_and_van_operational_roles_never_become_dashboard_administrators(): void
    {
        foreach (['B2C_DRIVER', 'B2B_DRIVER', 'VAN_OPERATOR'] as $code) {
            $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
            $role = Role::query()->where('code', $code)->firstOrFail();
            $user->roles()->attach($role->id);

            $this->assertTrue($user->hasRole($code));
            $this->assertFalse($user->hasPermission('users.view'));
            $this->assertFalse($user->hasPermission('roles.manage'));
            $this->assertFalse($user->hasPermission('finance.manage'));

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $user->forceFill(['locale' => $locale])->save();
                $user = $user->fresh();

                $this->assertSame([], app(AdminNavigation::class)->for($user));
                $this->actingAs($user)
                    ->get(route('admin.security.index'))
                    ->assertForbidden();
                $this->actingAs($user)
                    ->get(route('admin.b2b.module', ['module' => 'inventory']))
                    ->assertForbidden();
            }

            $this->assertDatabaseHas('role_user', [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }
    }

    public function test_global_allow_override_cannot_promote_a_roleless_customer_to_admin(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_platform_customer' => true,
            'locale' => 'ar',
        ]);

        foreach (['users.view', 'roles.manage'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $user->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user->forceFill(['has_permission_overrides' => true])->save();
        $user = $user->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $user = $user->fresh();

            $this->assertFalse($user->hasRole('SUPER_ADMIN'));
            $this->assertFalse($user->hasPermission('users.view'));
            $this->assertFalse($user->hasPermission('roles.manage'));
            $this->assertSame([], app(AdminNavigation::class)->for($user));
            $this->actingAs($user)
                ->get(route('admin.security.index'))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('user_permission_overrides', 2);
        $this->assertSame(0, $user->roles()->count());
    }
}
