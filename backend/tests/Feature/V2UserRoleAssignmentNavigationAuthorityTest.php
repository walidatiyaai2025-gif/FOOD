<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P336: the visible role picker and the server-side assignment endpoint
 * share the same role/scope authority. Presentation is never an RBAC grant.
 */
final class V2UserRoleAssignmentNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_role_picker_is_reachable_for_exact_user_in_arabic_and_english_and_persists_audited_global_grant(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['name' => 'P336 Assign Target', 'is_active' => true]);
        $role = $this->customRole('P336_GLOBAL_CATALOG', 'global', 'catalog.view');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->get(route('admin.security.index', ['section' => 'users', 'q' => $target->email]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($target->email)
                ->assertSee('action="'.route('admin.security.users.roles', $target).'"', false)
                ->assertSee('name="global_role_ids[]"', false);
        }

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'global_role_ids' => [$role->getKey()],
                'store_role_ids' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $role->getKey(),
        ]);
        $this->assertTrue($target->fresh()->hasPermission('catalog.view'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'user_id' => $admin->getKey(),
        ]);
    }

    public function test_read_only_security_viewer_cannot_render_or_submit_role_assignment_in_either_locale(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $viewer = User::factory()->create(['is_active' => true]);
        $role = $this->customRole('P336_READ_SECURITY_ONLY', 'global', 'security.view');
        $viewer->roles()->attach($role);
        $grant = $this->customRole('P336_TARGET_GRANT', 'global', 'catalog.view');

        $this->assertTrue($viewer->hasPermission('security.view'));
        $this->assertFalse($viewer->hasPermission('users.roles.manage'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($viewer)
                ->get(route('admin.security.index', ['section' => 'users', 'q' => $target->email]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('action="'.route('admin.security.users.roles', $target).'"', false);

            $this->actingAs($viewer)
                ->put(route('admin.security.users.roles', $target), [
                    'change_reason' => 'Regression fixture approved RBAC access change',
                    'global_role_ids' => [$grant->getKey()],
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $grant->getKey(),
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'user.roles_changed')
            ->where('user_id', $viewer->getKey())
            ->count());
    }

    public function test_roleless_user_cannot_enter_security_or_assign_any_role_directly(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);
        $role = $this->customRole('P336_NO_GRANT', 'global', 'catalog.view');

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->put(route('admin.security.users.roles', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'global_role_ids' => [$role->getKey()],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $role->getKey(),
        ]);
    }

    public function test_global_role_cannot_be_assigned_as_store_role_and_store_role_cannot_be_global(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $storeId = $this->retailStore();
        $global = $this->customRole('P336_GLOBAL_ONLY', 'global', 'catalog.view');
        $store = $this->customRole('P336_STORE_ONLY', 'store', 'inventory.view');

        $this->actingAs($admin)
            ->from(route('admin.security.index', ['section' => 'users']))
            ->put(route('admin.security.users.roles', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'global_role_ids' => [$store->getKey()],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('global_role_ids');

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'store_role_ids' => [$storeId => [$global->getKey()]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('store_roles');

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $store->getKey(),
        ]);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $target->getKey(),
            'store_id' => $storeId,
            'role_id' => $global->getKey(),
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'user.roles_changed')
            ->where('user_id', $admin->getKey())
            ->count());
    }

    public function test_store_role_assignment_is_scoped_to_exact_retail_store_and_logged(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $own = $this->retailStore();
        $other = $this->retailStore();
        $role = $this->customRole('P336_STORE_INVENTORY', 'store', 'inventory.view');

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'change_reason' => 'Regression fixture approved RBAC access change',
                'global_role_ids' => [],
                'store_role_ids' => [$own => [$role->getKey()]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->getKey(),
            'store_id' => $own,
            'role_id' => $role->getKey(),
        ]);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $target->getKey(),
            'store_id' => $other,
            'role_id' => $role->getKey(),
        ]);
        $this->assertTrue($target->fresh()->hasPermission('inventory.view', $own));
        $this->assertFalse($target->fresh()->hasPermission('inventory.view', $other));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'user_id' => $admin->getKey(),
        ]);
    }

    private function userWithGlobalRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function customRole(string $code, string $scope, string $permissionCode): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'description' => 'P336 test-only role',
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', $permissionCode)->firstOrFail()->getKey(),
        );

        return $role;
    }

    private function retailStore(): int
    {
        $code = 'P336-'.(string) DB::table('stores')->count().'-'.(string) random_int(100, 999);

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
