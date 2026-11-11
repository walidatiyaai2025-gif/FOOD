<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 P032: a view grant cannot create/update/delete, approve or execute.
 * All exercised HTTP routes use the existing canonical authorization layer.
 */
final class V2GranularActionPermissionHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_view_only_security_operator_can_read_but_cannot_mutate_roles_or_users(): void
    {
        $actor = $this->actor('V2_P032_VIEW', 'global', ['security.view']);
        $target = User::factory()->create(['is_active' => true]);
        $role = $this->role('V2_P032_TARGET', 'global');
        $auditBefore = DB::table('audit_logs')->count();
        $rolesBefore = DB::table('roles')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            Sanctum::actingAs($actor->fresh(), ['*']);
            $this->getJson('/api/v1/admin/security/permissions')->assertOk();
            $this->getJson('/api/v1/admin/security/roles')->assertOk();
            $this->getJson('/api/v1/admin/security/users')->assertForbidden();
            $this->postJson('/api/v1/admin/security/roles', [])->assertForbidden();
            $this->patchJson('/api/v1/admin/security/roles/'.$role->id, [])->assertForbidden();
            $this->postJson('/api/v1/admin/security/roles/'.$role->id.'/clone', [])->assertForbidden();
            $this->deleteJson('/api/v1/admin/security/roles/'.$role->id)->assertForbidden();
            $this->putJson('/api/v1/admin/security/users/'.$target->id.'/roles', [])->assertForbidden();
            $this->patchJson('/api/v1/admin/security/users/'.$target->id.'/status', [
                'is_active' => false,
                'reason' => 'Forbidden without users.status.manage',
            ])->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get('/admin/security?section=roles')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertSame($rolesBefore, DB::table('roles')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame(0, DB::table('user_store_roles')->where('user_id', $target->id)->count());
        $this->assertSame(0, DB::table('role_user')->where('user_id', $target->id)->count());
    }

    public function test_role_management_grant_does_not_implicitly_grant_security_read_or_user_management(): void
    {
        $actor = $this->actor('V2_P032_ROLE_WRITE', 'global', ['roles.manage']);
        $target = User::factory()->create(['is_active' => true]);

        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/v1/admin/security/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/security/permissions')->assertForbidden();
        // Authorization succeeds; the invalid request reaches validation.
        // It never creates a role, even for this legitimate role editor.
        $rolesBefore = DB::table('roles')->count();
        $this->postJson('/api/v1/admin/security/roles', [])->assertUnprocessable();
        $this->assertSame($rolesBefore, DB::table('roles')->count());
        $this->putJson('/api/v1/admin/security/users/'.$target->id.'/roles', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/security/users/'.$target->id.'/status', [
            'is_active' => false,
        ])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_explicit_deny_wins_over_role_management_grant_on_real_http_route(): void
    {
        $actor = $this->actor('V2_P032_DENY', 'global', ['security.view', 'roles.manage']);
        $permission = Permission::query()->where('code', 'roles.manage')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertTrue($actor->fresh()->hasPermission('security.view'));
        $this->assertFalse($actor->fresh()->hasPermission('roles.manage'));

        Sanctum::actingAs($actor->fresh(), ['*']);
        $this->getJson('/api/v1/admin/security/roles')->assertOk();
        $this->postJson('/api/v1/admin/security/roles', [])->assertForbidden();
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    public function test_store_scoped_permission_is_not_a_global_security_or_cross_tenant_grant(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-P032-RETAIL',
            'name' => 'V2 P032 retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = $this->actor('V2_P032_STORE', 'store', [
            'security.view', 'roles.manage', 'users.status.manage',
        ], false);
        $role = Role::query()->where('code', 'V2_P032_STORE')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target = User::factory()->create(['is_active' => true]);

        $this->assertFalse($actor->fresh()->hasPermission('roles.manage'));
        $this->assertFalse($actor->fresh()->hasPermission('security.view'));
        $this->assertTrue($actor->fresh()->hasPermission('roles.manage', $storeId));

        Sanctum::actingAs($actor->fresh(), ['*']);
        $this->getJson('/api/v1/admin/security/roles')->assertForbidden();
        $this->postJson('/api/v1/admin/security/roles', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/security/users/'.$target->id.'/status', [
            'is_active' => false,
        ])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_view_create_edit_delete_approve_and_execute_remain_independent_abilities(): void
    {
        $grants = ['catalog.view', 'orders.create', 'finance.approve'];
        $actor = $this->actor('V2_P032_ACTIONS', 'global', $grants);

        foreach ([
            'catalog.view' => true,
            'catalog.create' => false,
            'catalog.edit' => false,
            'catalog.delete' => false,
            'catalog.manage' => false,
            'orders.view' => false,
            'orders.create' => true,
            'orders.edit' => false,
            'orders.approve' => false,
            'orders.dispatch' => false,
            'finance.approve' => true,
            'finance.post' => false,
            'deliveries.b2b.execute' => false,
            'deliveries.b2c.execute' => false,
        ] as $ability => $allowed) {
            $this->assertSame($allowed, Gate::forUser($actor)->allows($ability), $ability);
        }

        $this->assertCount(count($grants), $actor->fresh()->effectivePermissionCodes());
    }

    /** @param list<string> $abilities */
    private function actor(string $code, string $scope, array $abilities, bool $global = true): User
    {
        $role = $this->role($code, $scope);
        $permissionIds = Permission::query()->whereIn('code', $abilities)->pluck('id');
        $this->assertCount(count($abilities), $permissionIds);
        $role->permissions()->attach($permissionIds->all());

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        if ($global) {
            $actor->roles()->attach($role);
        }

        return $actor->fresh();
    }

    private function role(string $code, string $scope): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
