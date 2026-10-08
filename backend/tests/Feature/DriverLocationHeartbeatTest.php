<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverLocationHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_driver_can_upsert_current_location_without_enforcement(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'B2B Driver',
            'email' => 'tracking-driver@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['app:driver']);

        $payload = [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 8.5,
            'speed' => 3.2,
            'heading' => 90,
            'captured_at' => now()->subSecond()->toISOString(),
            'app_version' => '1.0.38+38',
            'is_mocked' => false,
        ];

        $this->postJson('/api/v1/driver/location/heartbeat', $payload)
            ->assertOk()
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.store_id', app(WholesalePrincipal::class)->storeId());

        $this->assertDatabaseCount('driver_current_locations', 1);

        $payload['latitude'] = 29.3760;
        $payload['captured_at'] = now()->toISOString();

        $this->postJson('/api/v1/driver/location/heartbeat', $payload)->assertOk();

        $this->assertDatabaseCount('driver_current_locations', 1);
        $this->assertDatabaseHas('driver_current_locations', [
            'driver_id' => $driver->id,
            'channel' => 'b2b',
            'store_id' => app(WholesalePrincipal::class)->storeId(),
        ]);
    }

    public function test_stale_heartbeat_cannot_overwrite_newer_current_location(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Ordered B2B Driver',
            'email' => 'ordered-tracking-driver@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['app:driver']);

        $newerCapturedAt = now()->subSecond();
        $olderCapturedAt = $newerCapturedAt->copy()->subMinute();

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.4001,
            'longitude' => 47.9001,
            'accuracy' => 5,
            'captured_at' => $newerCapturedAt->toISOString(),
            'app_version' => '1.0.38+38',
        ])->assertOk();

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3001,
            'longitude' => 47.8001,
            'accuracy' => 50,
            'captured_at' => $olderCapturedAt->toISOString(),
            'app_version' => '1.0.37+37',
        ])
            ->assertOk()
            ->assertJsonPath('data.driver_id', $driver->id);

        $row = DB::table('driver_current_locations')
            ->where('driver_id', $driver->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(29.4001, (float) $row->latitude, 0.0000001);
        $this->assertEqualsWithDelta(47.9001, (float) $row->longitude, 0.0000001);
        $this->assertSame(
            $newerCapturedAt->format('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime((string) $row->captured_at)),
        );
        $this->assertSame('1.0.38+38', $row->app_version);
    }

    public function test_heartbeat_rejects_far_future_capture_time(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Future Location Driver',
            'email' => 'future-tracking-driver@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());
        Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['app:driver']);

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'captured_at' => now()->addMinutes(10)->toISOString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captured_at']);
    }

    public function test_heartbeat_rejects_invalid_coordinates(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Invalid Location Driver',
            'email' => 'invalid-tracking-driver@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());
        Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['app:driver']);

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 91,
            'longitude' => 181,
            'captured_at' => now()->toISOString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }
}
