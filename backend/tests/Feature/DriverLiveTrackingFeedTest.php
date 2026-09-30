<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\DriverCurrentLocation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverLiveTrackingFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_tracking_feed_is_limited_to_assigned_store(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'TRACK-A',
            'name' => 'Tracking A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeB = DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'TRACK-B',
            'name' => 'Tracking B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'tracking-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeA,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverA = $this->driver('B2C_DRIVER', 'tracking-a@example.test', 'b2c', $storeA);
        $driverB = $this->driver('B2C_DRIVER', 'tracking-b@example.test', 'b2c', $storeB);

        $this->location($driverA, $storeA, 'b2c', 29.3759, 47.9774);
        $this->location($driverB, $storeB, 'b2c', 29.3500, 47.9500);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/driver-live-tracking/feed?channel=b2c')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver_id', $driverA->id)
            ->assertJsonPath('data.0.store_id', $storeA)
            ->assertJsonPath('data.0.status', 'online');

        $this->getJson("/api/v1/admin/driver-live-tracking/feed?channel=b2c&store_id={$storeB}")
            ->assertNotFound();
    }

    public function test_b2b_tracking_feed_does_not_leak_retail_locations(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $b2bStore = DB::table('stores')->insertGetId([
            'store_type_id' => $b2bTypeId,
            'code' => 'TRACK-W',
            'name' => 'Tracking Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2cStore = DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'TRACK-R',
            'name' => 'Tracking Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->roleUser('B2B_ADMIN', 'tracking-b2b-admin@example.test');
        $wholesaleDriver = $this->driver('B2B_DRIVER', 'tracking-wholesale@example.test', 'b2b', $b2bStore);
        $retailDriver = $this->driver('B2C_DRIVER', 'tracking-retail@example.test', 'b2c', $b2cStore);

        $this->location($wholesaleDriver, $b2bStore, 'b2b', 29.3800, 47.9800);
        $this->location($retailDriver, $b2cStore, 'b2c', 29.3600, 47.9600);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/driver-live-tracking/feed?channel=b2b')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver_id', $wholesaleDriver->id)
            ->assertJsonPath('data.0.channel', 'b2b');

        $this->getJson('/api/v1/admin/driver-live-tracking/feed?channel=b2c')
            ->assertForbidden();
    }

    private function roleUser(string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function driver(string $role, string $email, string $channel, int $storeId): Driver
    {
        $user = $this->roleUser($role, $email);

        return Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => $channel,
            'is_available' => true,
            'is_active' => true,
        ]);
    }

    private function location(
        Driver $driver,
        int $storeId,
        string $channel,
        float $latitude,
        float $longitude,
    ): void {
        DriverCurrentLocation::query()->create([
            'driver_id' => $driver->id,
            'store_id' => $storeId,
            'channel' => $channel,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => 5,
            'captured_at' => now(),
            'received_at' => now(),
            'app_version' => '1.0.38+38',
            'is_mocked' => false,
        ]);
    }
}
