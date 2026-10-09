<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VanAccessBootstrapHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_database_missing_van_role_self_heals_and_driver_can_receive_van_access(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $driverUser = User::factory()->create(['is_active' => true]);
        $driverUser->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'HOTFIX-VAN-1',
            'status' => 'active',
        ]);

        $vanRoleId = DB::table('roles')->where('code', 'VAN_OPERATOR')->value('id');
        $vanPermissionId = DB::table('permissions')->where('code', 'van.login')->value('id');

        DB::table('permission_role')->where('role_id', $vanRoleId)->delete();
        DB::table('role_user')->where('role_id', $vanRoleId)->delete();
        DB::table('roles')->where('id', $vanRoleId)->delete();
        DB::table('permissions')->where('id', $vanPermissionId)->delete();

        $this->actingAs($admin)
            ->post('/admin/field-operations/vans/'.$van->id.'/assignments', [
                'driver_id' => $driver->id,
                'assignment_type' => 'primary',
                'effective_from' => now()->subMinute()->toDateTimeString(),
                'allow_van_app' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('roles', ['code' => 'VAN_OPERATOR', 'is_active' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'van.login']);

        $roleId = DB::table('roles')->where('code', 'VAN_OPERATOR')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'van.login')->value('id');

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
        $this->assertDatabaseHas('role_user', [
            'role_id' => $roleId,
            'user_id' => $driverUser->id,
        ]);
        $this->assertDatabaseHas('van_assignments', [
            'van_id' => $van->id,
            'driver_id' => $driver->id,
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $driverUser->email,
            'password' => 'password',
            'app' => 'van',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.van_scope.van_id', $van->id)
            ->assertJsonPath('user.permissions.0', 'deliveries.b2b.execute');

        $this->assertContains('van.login', $login->json('user.permissions'));
    }

    public function test_upgrade_migration_bootstraps_van_runtime_reference_data_idempotently(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $vanRoleId = DB::table('roles')->where('code', 'VAN_OPERATOR')->value('id');
        $vanPermissionId = DB::table('permissions')->where('code', 'van.login')->value('id');

        DB::table('permission_role')->where('role_id', $vanRoleId)->delete();
        DB::table('roles')->where('id', $vanRoleId)->delete();
        DB::table('permissions')->where('id', $vanPermissionId)->delete();

        $migration = require database_path('migrations/2026_10_09_174800_bootstrap_van_runtime_access_reference_data.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('roles')->where('code', 'VAN_OPERATOR')->count());
        $this->assertSame(1, DB::table('permissions')->where('code', 'van.login')->count());
        $this->assertSame(1, DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.code', 'VAN_OPERATOR')
            ->where('permissions.code', 'van.login')
            ->count());
    }
}
