<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VanAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_can_be_edited_and_scoped_delete_purges_only_attributed_operational_records(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'ASSIGN-ACTIONS-1',
            'status' => 'active',
        ]);

        $otherVan = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'ASSIGN-ACTIONS-2',
            'status' => 'active',
        ]);

        $service = app(VanRegistryService::class);
        $assignment = $service->assign($admin, $van, [
            'representative_user_id' => $admin->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subHours(2),
            'effective_until' => now()->addHours(2),
            'loaded_work_count' => 1,
        ]);

        $updated = $service->updateAssignment($admin, $assignment, [
            'representative_user_id' => $admin->id,
            'warehouse_id' => null,
            'territory_key' => null,
            'van_pool_key' => 'POOL-A',
            'assignment_type' => 'backup',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'loaded_work_count' => 3,
        ]);

        $this->assertSame('backup', $updated->assignment_type);
        $this->assertSame('POOL-A', $updated->van_pool_key);
        $this->assertSame(3, (int) $updated->loaded_work_count);

        $linkedVisit = VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 1001,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'van_id' => $van->id,
                'van_assignment_id' => $updated->id,
            ],
        ]);

        $unrelatedVisit = VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 1002,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'van_id' => $otherVan->id,
                'van_assignment_id' => 999999,
            ],
        ]);

        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'vehicle_id' => $van->id,
            'assignment_id' => $updated->id,
            'latitude' => 29.3759000,
            'longitude' => 47.9774000,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $otherVan->id,
            'vehicle_id' => $otherVan->id,
            'assignment_id' => 999999,
            'latitude' => 30.0444000,
            'longitude' => 31.2357000,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counts = $service->deleteAssignmentWithOperations($admin, $updated);

        $this->assertSame(1, $counts['visits']);
        $this->assertSame(1, $counts['fleet_locations']);
        $this->assertDatabaseMissing('van_assignments', ['id' => $updated->id]);
        $this->assertDatabaseMissing('van_visits', ['id' => $linkedVisit->id]);
        $this->assertDatabaseHas('van_visits', ['id' => $unrelatedVisit->id]);
        $this->assertDatabaseMissing('fleet_current_locations', [
            'actor_type' => 'van',
            'actor_id' => $van->id,
        ]);
        $this->assertDatabaseHas('fleet_current_locations', [
            'actor_type' => 'van',
            'actor_id' => $otherVan->id,
        ]);
    }

    public function test_dashboard_exposes_three_dot_edit_and_delete_assignment_actions(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'ASSIGN-UI-1',
            'status' => 'active',
        ]);

        VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'representative_user_id' => $admin->id,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinute(),
            'loaded_work_count' => 0,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/admin/field-operations/assignments')
            ->assertOk()
            ->assertSee('⋮')
            ->assertSee(route('admin.field-operations.assignments.update', 1), false)
            ->assertSee(route('admin.field-operations.assignments.destroy', 1), false);
    }
}
