<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_system_update_center(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('System Update')
            ->assertSee('Validated update package');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('href="'.route('admin.administration.index').'"', false)
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    public function test_system_update_center_repairs_legacy_compact_installed_version(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        SystemVersion::query()->create([
            'version' => '1.084',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.083',
            'to_version' => '1.084',
            'status' => 'success',
            'package_hash' => str_repeat('a', 64),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.084',
            'to_version' => '1.0.85',
            'status' => 'failed',
            'package_hash' => str_repeat('b', 64),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('1.0.84');

        $this->assertDatabaseMissing('system_versions', ['version' => '1.084']);
        $this->assertDatabaseHas('system_versions', ['version' => '1.0.84']);
        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.0.84',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('update_history', [
            'from_version' => '1.0.84',
            'to_version' => '1.0.85',
            'status' => 'failed',
        ]);
    }


    public function test_update_center_renders_sanitized_execution_evidence_without_private_backup_paths(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        UpdateHistory::query()->create([
            'actor_id' => $user->id,
            'from_version' => '1.0.90',
            'to_version' => '1.0.91',
            'status' => 'failed',
            'environment' => 'production',
            'backup_status' => 'available',
            'file_backup_reference' => '/private/system/update-backups/secret-files-path',
            'database_backup_reference' => '/private/system/update-backups/secret-db-path',
            'failed_stage' => 'health_check',
            'rollback_status' => 'succeeded',
            'package_hash' => str_repeat('c', 64),
            'failure_reason' => 'Update failed during [health_check]. Rollback was attempted.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'execution_evidence' => [
                'package_integrity' => 'passed',
                'preflight' => 'passed',
                'file_backup' => ['reference' => '20261017-files-abc', 'size_bytes' => 2048, 'sha256' => str_repeat('d', 64)],
                'database_backup' => ['reference' => '20261017-database-def', 'size_bytes' => 4096, 'sha256' => str_repeat('e', 64)],
                'migrations' => 'passed',
                'health_check' => 'failed',
                'rollback_files' => 'passed',
                'rollback_database' => 'passed',
            ],
        ]);

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('Execution evidence')
            ->assertSee('20261017-files-abc')
            ->assertSee('20261017-database-def')
            ->assertSee('health_check')
            ->assertDontSee('/private/system/update-backups/secret-files-path')
            ->assertDontSee('/private/system/update-backups/secret-db-path');
    }

    public function test_non_update_admin_cannot_open_system_update_center(): void
    {
        $user = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-update@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
