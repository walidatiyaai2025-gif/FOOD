<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverLocationEnforcementPolicy;
use App\Services\DriverRuntimeContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class V2DriverPinnedRuntimeProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_retail_heartbeat_and_freshness_keep_the_pinned_store(): void
    {
        [$user, $driver, $storeId] = $this->retailDriver();
        Sanctum::actingAs($user, ['app:driver']);

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'captured_at' => now()->toISOString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.driver_id', $driver->getKey())
            ->assertJsonPath('data.channel', 'b2c')
            ->assertJsonPath('data.store_id', $storeId);

        app(DriverLocationEnforcementPolicy::class)->persist(true, 90);

        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->assertDatabaseHas('driver_current_locations', [
            'driver_id' => $driver->getKey(),
            'store_id' => $storeId,
            'channel' => 'b2c',
        ]);
        $this->assertDatabaseHas('fleet_current_locations', [
            'actor_type' => 'driver',
            'actor_id' => $driver->getKey(),
            'store_id' => $storeId,
            'channel' => 'b2c',
        ]);
    }

    public function test_reassignment_to_another_active_retail_store_after_authorization_fails_closed(): void
    {
        [$user, $driver, $authorizedStoreId] = $this->retailDriver();
        $request = $this->runtimeRequest($user, [
            'driver_id' => (int) $driver->getKey(),
            'channel' => 'b2c',
            'store_id' => $authorizedStoreId,
        ]);

        $newStoreId = $this->retailStore('V2-DRIVER-PIN-B');
        $driver->forceFill(['store_id' => $newStoreId])->save();

        // The new store is independently active and Retail. The only denial
        // is the drift from the already authorized request store snapshot.
        $this->assertNotSame($authorizedStoreId, $newStoreId);
        $this->assertRejectedContext($request);

        $this->assertDatabaseCount('driver_current_locations', 0);
        $this->assertDatabaseCount('fleet_current_locations', 0);
    }

    public function test_absent_and_wrong_pinned_driver_context_fail_closed(): void
    {
        [$user, $driver, $storeId] = $this->retailDriver();

        $this->assertRejectedContext($this->runtimeRequest($user));

        $this->assertRejectedContext($this->runtimeRequest($user, [
            'driver_id' => (int) $driver->getKey() + 1000,
            'channel' => 'b2c',
            'store_id' => $storeId,
        ]));

        $this->assertRejectedContext($this->runtimeRequest($user, [
            'driver_id' => (int) $driver->getKey(),
            'channel' => 'b2b',
            'store_id' => $storeId,
        ]));

        $this->assertDatabaseCount('driver_current_locations', 0);
        $this->assertDatabaseCount('fleet_current_locations', 0);
    }

    public function test_matching_authorized_request_context_resolves_the_existing_driver(): void
    {
        [$user, $driver, $storeId] = $this->retailDriver();

        [$resolved, $channel, $scopeStore] = app(DriverRuntimeContextResolver::class)->resolve(
            $this->runtimeRequest($user, [
                'driver_id' => (int) $driver->getKey(),
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]),
        );

        $this->assertSame((int) $driver->getKey(), (int) $resolved->getKey());
        $this->assertSame('b2c', $channel);
        $this->assertSame($storeId, $scopeStore);
    }

    /** @return array{0:User,1:Driver,2:int} */
    private function retailDriver(): array
    {
        $storeId = $this->retailStore('V2-DRIVER-PIN-A');

        $user = User::query()->create([
            'name' => 'Pinned Retail Driver',
            'email' => 'v2-driver-pinned@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver, $storeId];
    }

    private function retailStore(string $code): int
    {
        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => $code,
            'name' => 'V2 Pinned Driver Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>|null  $context */
    private function runtimeRequest(User $user, ?array $context = null): Request
    {
        $request = Request::create('/api/v1/driver/location/heartbeat', 'POST');
        $request->setUserResolver(static fn () => $user);

        if ($context !== null) {
            $request->attributes->set('driver_runtime_context', $context);
        }

        return $request;
    }

    private function assertRejectedContext(Request $request): void
    {
        try {
            app(DriverRuntimeContextResolver::class)->resolve($request);
            $this->fail('A stale or missing middleware-authorized scope must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }
}
