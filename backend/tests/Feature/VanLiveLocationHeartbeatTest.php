<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VanLiveLocationHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_runtime_heartbeat_is_bound_to_authenticated_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'VAN-LIVE-B2B',
            'name' => 'Van Live B2B Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'VAN-LIVE-WH',
            'name' => 'Van Live Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Live Van Operator',
            'email' => 'live-van@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan([
            'code' => 'LIVE-VAN-01',
            'home_warehouse_id' => $warehouseId,
        ]);
        $assignment = $registry->assign($user, $van, [
            'representative_user_id' => $user->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/van/location/heartbeat', [
                'van_id' => 999999,
                'assignment_id' => 999999,
                'latitude' => 29.3759,
                'longitude' => 47.9774,
                'accuracy' => 8.5,
                'captured_at' => now()->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.actor_type', 'van')
            ->assertJsonPath('data.actor_id', $van->id)
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.store_id', $storeId)
            ->assertJsonPath('data.channel', 'b2b');

        $this->assertDatabaseHas('fleet_current_locations', [
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'assignment_id' => $assignment->id,
            'store_id' => $storeId,
            'channel' => 'b2b',
            'source_app' => 'van',
        ]);
        $this->assertDatabaseMissing('fleet_current_locations', [
            'actor_type' => 'van',
            'actor_id' => 999999,
        ]);
    }

    public function test_super_admin_fleet_map_keeps_unscoped_van_locations_visible(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        DB::table('stores')->insert([
            'store_type_id' => $typeId,
            'code' => 'FLEET-MAP-B2B',
            'name' => 'Fleet Map B2B Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => 'Fleet Admin',
            'email' => 'fleet-admin@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $van = app(VanRegistryService::class)->createVan(['code' => 'UNSCOPED-LIVE-VAN']);

        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'vehicle_id' => $van->id,
            'assignment_id' => null,
            'route_key' => null,
            'store_id' => null,
            'channel' => 'b2b',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 5,
            'speed' => 0,
            'heading' => 0,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
            'app_version' => '1.0.70',
            'is_mocked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/admin/field-operations/fleet/feed?actor_type=van')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actor_id', $van->id)
            ->assertJsonPath('data.0.entity_name', 'UNSCOPED-LIVE-VAN');
    }
}
