<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductionDatasetSwitchController;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductionDatasetSnapshot;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_dashboard_truthfully_reports_launch_state_snapshot_and_audit_reference(): void
    {
        $owner = $this->superAdmin();
        $this->writeState(ProductionDatasetSnapshot::LAUNCH_ACTIVE, true);
        $audit = AuditLog::query()->create([
            'user_id' => $owner->id,
            'event' => 'system.production_dataset.switched_to_launch',
            'after' => ['state' => ProductionDatasetSnapshot::LAUNCH_ACTIVE],
        ]);

        $this->actingAs($owner)->get('/admin/settings/production-dataset')
            ->assertOk()
            ->assertSee('data-production-dataset-switch="true"', false)
            ->assertSee('data-active-mode="launch"', false)
            ->assertSee('snapshot-test-1385')
            ->assertSee('#'.$audit->id)
            ->assertSee(ProductionDatasetSwitchController::LEGACY_CONFIRMATION);
    }

    public function test_only_super_admin_can_use_switch_and_confirmation_is_fail_closed(): void
    {
        $owner = $this->superAdmin();
        $this->writeState(ProductionDatasetSnapshot::LAUNCH_ACTIVE, true);

        $this->actingAs($owner)->post('/admin/settings/production-dataset/legacy', [
            'confirmation' => 'WRONG',
            'current_password' => 'password',
            'understood' => '1',
        ])->assertSessionHasErrors('confirmation');

        $this->actingAs($owner)->post('/admin/settings/production-dataset/legacy', [
            'confirmation' => ProductionDatasetSwitchController::LEGACY_CONFIRMATION,
            'current_password' => 'wrong',
            'understood' => '1',
        ])->assertSessionHasErrors('current_password');

        $regular = User::query()->create([
            'name' => 'Dataset Regular', 'email' => 'dataset-regular@example.test',
            'password' => 'password', 'locale' => 'en', 'is_active' => true,
        ]);
        $this->actingAs($regular)->get('/admin/settings/production-dataset')->assertForbidden();
    }

    public function test_transition_and_failure_states_lock_both_actions(): void
    {
        $owner = $this->superAdmin();
        foreach ([ProductionDatasetSnapshot::RESTORING, ProductionDatasetSnapshot::FAILED] as $state) {
            $this->writeState($state, true);
            $this->actingAs($owner)->get('/admin/settings/production-dataset')
                ->assertOk()
                ->assertSee('data-active-mode="'.$state.'"', false)
                ->assertSee('disabled', false);
        }
    }

    public function test_legacy_restored_state_allows_a_new_verified_launch_cycle(): void
    {
        $owner = $this->superAdmin();
        $this->writeState(ProductionDatasetSnapshot::LEGACY_RESTORED, true);

        $this->actingAs($owner)->get('/admin/settings/production-dataset')
            ->assertOk()
            ->assertSee('data-active-mode="legacy"', false)
            ->assertSee(ProductionDatasetSwitchController::LAUNCH_CONFIRMATION)
            ->assertDontSee('name="confirmation" autocomplete="off" required disabled', false);
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
            'name' => 'Dataset Switch Owner', 'email' => 'dataset-owner-'.uniqid().'@example.test',
            'password' => 'password', 'locale' => 'en', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        return $user;
    }
}
