<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2UpdaterLegacyPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_old_database_receives_permission_without_an_implicit_role_grant(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $permissionId = (int) DB::table('permissions')->where('code', 'users.delete')->value('id');
        $this->assertGreaterThan(0, $permissionId);

        // Recreate the historical v1.0.125 permission catalog, without
        // changing the built-in role assignments belonging to that install.
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        $beforePermissions = DB::table('permissions')->count();
        $beforeGrants = DB::table('permission_role')->count();

        $migration = require database_path(
            'migrations/2026_11_17_113000_restore_user_delete_permission_for_upgrade.php',
        );
        $migration->up();
        $migration->up();

        $this->assertSame($beforePermissions + 1, DB::table('permissions')->count());
        $this->assertDatabaseHas('permissions', [
            'code' => 'users.delete',
            'name' => 'Delete or archive user accounts after protected lifecycle approval',
        ]);
        $this->assertSame($beforeGrants, DB::table('permission_role')->count());
        $restoredId = DB::table('permissions')->where('code', 'users.delete')->value('id');
        $this->assertDatabaseMissing('permission_role', ['permission_id' => $restoredId]);

        $migration->down();
        $this->assertDatabaseHas('permissions', ['code' => 'users.delete']);
    }

    public function test_existing_permission_description_and_role_assignments_are_preserved(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $permissionId = (int) DB::table('permissions')->where('code', 'users.delete')->value('id');
        $this->assertGreaterThan(0, $permissionId);
        $roleIds = DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();

        DB::table('permissions')->where('id', $permissionId)
            ->update(['name' => 'Previously approved local description']);

        $migration = require database_path(
            'migrations/2026_11_17_113000_restore_user_delete_permission_for_upgrade.php',
        );
        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('permissions', [
            'id' => $permissionId,
            'code' => 'users.delete',
            'name' => 'Previously approved local description',
        ]);
        $this->assertSame($roleIds, DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all());
    }
}
