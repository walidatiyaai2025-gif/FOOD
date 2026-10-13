<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ProductionDatasetSnapshot;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionDatasetSwitchTest extends TestCase
{
    use RefreshDatabase;

    private string $metadataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->metadataPath = storage_path('framework/testing/dataset-switch-'.bin2hex(random_bytes(5)).'.json');
        config(['foodex_reset.snapshot_metadata_path' => $this->metadataPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->metadataPath);
        parent::tearDown();
    }

    public function test_dashboard_reports_actual_launch_state_and_audit_reference(): void
    {
        $owner = $this->superAdmin();
        $this->writeState(ProductionDatasetSnapshot::LAUNCH_ACTIVE, true);
        DB::table('audit_logs')->insert([
            'user_id' => $owner->id,
            'event' => 'system.production_snapshot.launch_active',
            'after' => json_encode(['state' => ProductionDatasetSnapshot::LAUNCH_ACTIVE]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get('/admin/settings/foodex-reset')
            ->assertOk()
            ->assertSee('data-dataset-state="launch_active"', false)
            ->assertSee('data-active-data-mode="launch_active"', false)
            ->assertSee('REVERT TO LEGACY')
            ->assertSee('system.production_snapshot.launch_active');
    }

    public function test_revert_is_super_admin_password_and_exact_phrase_protected(): void
    {
        $owner = $this->superAdmin();
        $this->writeState(ProductionDatasetSnapshot::LAUNCH_ACTIVE, true);

        $this->actingAs($owner)->post('/admin/settings/foodex-reset', [
            'dataset_action' => 'revert',
            'confirmation' => 'WRONG',
            'current_password' => 'password',
            'understood' => '1',
        ])->assertSessionHasErrors('confirmation');

        $this->actingAs($owner)->post('/admin/settings/foodex-reset', [
            'dataset_action' => 'revert',
            'confirmation' => 'REVERT TO LEGACY',
            'current_password' => 'wrong',
            'understood' => '1',
        ])->assertSessionHasErrors('current_password');

        $regular = User::query()->create([
            'name' => 'Dataset Regular',
            'email' => 'dataset-regular@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->actingAs($regular)->post('/admin/settings/foodex-reset', [
            'dataset_action' => 'revert',
            'confirmation' => 'REVERT TO LEGACY',
            'current_password' => 'password',
            'understood' => '1',
        ])->assertForbidden();
    }

    public function test_transitioning_or_failed_state_locks_launch_controls(): void
    {
        $owner = $this->superAdmin();

        foreach ([ProductionDatasetSnapshot::RESTORING, ProductionDatasetSnapshot::FAILED] as $state) {
            $this->writeState($state, true);
            $this->actingAs($owner)
                ->get('/admin/settings/foodex-reset')
                ->assertOk()
                ->assertSee('data-transition-locked="true"', false)
                ->assertSee('disabled', false);
        }
    }

    private function writeState(string $state, bool $verified): void
    {
        if (! is_dir(dirname($this->metadataPath))) {
            mkdir(dirname($this->metadataPath), 0750, true);
        }
        file_put_contents($this->metadataPath, json_encode([
            'state' => $state,
            'snapshot_id' => 'snapshot-test-1385',
            'verified' => $verified,
            'created_at' => now()->subMinute()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Dataset Switch Owner',
            'email' => 'dataset-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
