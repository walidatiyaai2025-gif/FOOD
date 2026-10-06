<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MobileSystemInspectorEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Cache::flush();
    }

    public function test_customer_runtime_event_is_sanitized_deduplicated_and_visible_to_super_admin(): void
    {
        [$customer, $storeId] = $this->retailCustomer('central-inspector-customer@example.test');
        Sanctum::actingAs($customer);

        $payload = [
            'app' => 'customer',
            'category' => 'api_failure',
            'severity' => 'error',
            'message' => 'Bearer customer-token central-inspector-customer@example.test coordinates=29.375859,47.977405',
            'app_version' => '1.0.53',
            'platform' => 'android',
            'current_route' => '/b2b/orders?token=route-secret',
            'channel' => 'b2c',
            'store_id' => $storeId,
            'method' => 'GET',
            'path' => '/api/v1/orders?token=query-secret',
            'status' => 503,
            'correlation_id' => 'cid-customer-896',
            'metadata' => [
                'elapsed_ms' => 900,
                'refresh_token' => 'refresh-secret',
                'customer_email' => 'private@example.test',
            ],
        ];

        $this->postJson('/api/v1/runtime-inspector/events', $payload)->assertAccepted();
        $this->postJson('/api/v1/runtime-inspector/events', $payload)->assertAccepted();

        $this->assertSame(1, SystemInspectorEvent::query()->where('source', 'customer_app')->count());
        $event = SystemInspectorEvent::query()->where('source', 'customer_app')->firstOrFail();

        $this->assertSame($customer->id, $event->user_id);
        $this->assertSame($storeId, $event->store_id);
        $this->assertSame(503, $event->status_code);
        $this->assertSame('/api/v1/orders', $event->url);
        $this->assertSame('cid-customer-896', $event->correlation_id);
        $this->assertSame('1.0.53', $event->context['app_version']);
        $this->assertSame('b2c', $event->context['channel']);

        $encoded = json_encode([
            'message' => $event->message,
            'url' => $event->url,
            'context' => $event->context,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('customer-token', $encoded);
        $this->assertStringNotContainsString('central-inspector-customer@example.test', $encoded);
        $this->assertStringNotContainsString('29.375859', $encoded);
        $this->assertStringNotContainsString('47.977405', $encoded);
        $this->assertStringNotContainsString('route-secret', $encoded);
        $this->assertStringNotContainsString('query-secret', $encoded);
        $this->assertStringNotContainsString('refresh-secret', $encoded);
        $this->assertStringNotContainsString('private@example.test', $encoded);

        $this->actingAs($customer)
            ->get(route('admin.inspector.index'))
            ->assertForbidden();

        $admin = $this->superAdmin('central-inspector-admin@example.test');
        $this->actingAs($admin)
            ->get(route('admin.inspector.index', [
                'source' => 'customer_app',
                'app_version' => '1.0.53',
                'channel' => 'b2c',
                'store_id' => $storeId,
                'q' => 'cid-customer-896',
            ]))
            ->assertOk()
            ->assertSee('cid-customer-896');
    }

    public function test_driver_runtime_event_requires_driver_identity_and_owned_store(): void
    {
        $storeId = $this->retailStore('INSPECTOR-DRIVER');
        $driverUser = User::query()->create([
            'name' => 'Inspector Driver',
            'email' => 'central-inspector-driver@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/runtime-inspector/events', [
            'app' => 'driver',
            'category' => 'http_failure',
            'severity' => 'error',
            'message' => 'Driver assignment request failed',
            'app_version' => '1.0.53',
            'app_build' => '53',
            'platform' => 'android',
            'channel' => 'b2c',
            'store_id' => $storeId,
            'path' => '/api/v1/driver/assignments/123?token=hidden',
            'status' => 500,
        ])->assertAccepted();

        $this->assertDatabaseHas('system_inspector_events', [
            'source' => 'driver_app',
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'status_code' => 500,
        ]);

        $otherStore = $this->retailStore('INSPECTOR-FOREIGN');
        $this->postJson('/api/v1/runtime-inspector/events', [
            'app' => 'driver',
            'category' => 'http_failure',
            'message' => 'Cross-store attempt',
            'store_id' => $otherStore,
        ])->assertForbidden();
    }

    public function test_van_runtime_event_is_first_class_and_sanitizes_sensitive_location_context(): void
    {
        $admin = $this->superAdmin('central-inspector-van@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/runtime-inspector/events', [
            'app' => 'van',
            'category' => 'location_failure',
            'severity' => 'error',
            'message' => 'Van sync failed latitude=29.375859 longitude=47.977405',
            'app_version' => '1.0.57',
            'app_build' => '57',
            'platform' => 'android',
            'route_id' => 55,
            'manifest_id' => 56,
            'visit_id' => 66,
            'collection_id' => 67,
            'remittance_id' => 68,
            'metadata' => [
                'latitude' => 29.375859,
                'longitude' => 47.977405,
                'access_token' => 'van-secret',
                'sync_state' => 'pending',
            ],
        ])->assertAccepted();

        $event = SystemInspectorEvent::query()->where('source', 'van_app')->firstOrFail();
        $this->assertSame('1.0.57', $event->context['app_version']);
        $this->assertSame(55, $event->context['route_id']);
        $this->assertSame(56, $event->context['manifest_id']);
        $this->assertSame(66, $event->context['visit_id']);
        $this->assertSame(67, $event->context['collection_id']);
        $this->assertSame(68, $event->context['remittance_id']);
        $encoded = json_encode($event->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('29.375859', $encoded);
        $this->assertStringNotContainsString('47.977405', $encoded);
        $this->assertStringNotContainsString('van-secret', $encoded);
    }

    public function test_customer_cannot_submit_van_runtime_events(): void
    {
        [$customer] = $this->retailCustomer('inspector-not-van@example.test');
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/runtime-inspector/events', [
            'app' => 'van',
            'category' => 'runtime_error',
            'message' => 'Not a Van operator',
        ])->assertForbidden();
    }

    public function test_non_driver_cannot_submit_driver_events(): void
    {
        [$customer] = $this->retailCustomer('inspector-not-driver@example.test');
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/runtime-inspector/events', [
            'app' => 'driver',
            'category' => 'runtime_error',
            'message' => 'Not a driver',
        ])->assertForbidden();
    }

    public function test_unhandled_api_exception_is_recorded_with_correlation_id(): void
    {
        Route::middleware('api')->get('/api/v1/_inspector-test-crash', static function (): never {
            throw new RuntimeException('Synthetic inspector API failure');
        });

        $response = $this->getJson('/api/v1/_inspector-test-crash');
        $response->assertStatus(500)
            ->assertJsonPath('message', 'An unexpected server error occurred.');

        $event = SystemInspectorEvent::query()
            ->where('source', 'api')
            ->where('status_code', 500)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Synthetic inspector API failure', $event->message);
        $this->assertNotNull($event->correlation_id);
        $this->assertSame('/api/v1/_inspector-test-crash', $event->url);
        $this->assertIsArray($event->context);
        $this->assertStringNotContainsString(base_path(), json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    /** @return array{User,int} */
    private function retailCustomer(string $email): array
    {
        $storeId = $this->retailStore('INSPECTOR-CUSTOMER');
        $user = User::query()->create([
            'name' => 'Inspector Customer',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('b2c_customers')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'name' => 'Inspector Customer',
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $storeId];
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => str_replace('-', ' ', $code),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Inspector Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        return $user;
    }
}
