<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use App\Services\SystemInspectorRecorder;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SystemInspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authenticated_admin_client_errors_are_captured_and_super_admin_can_export_them(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'inspector-owner@example.test');

        $this->actingAs($admin)->postJson(route('admin.inspector.client-events'), [
            'source' => 'javascript',
            'severity' => 'error',
            'message' => 'ReferenceError: demo is not defined',
            'url' => 'https://foodex.example.test/admin/b2c/products',
            'filename' => 'app.js',
            'line' => 42,
            'column' => 7,
            'stack' => "ReferenceError: demo is not defined\n at app.js:42:7",
        ])->assertAccepted();

        $this->assertDatabaseHas('system_inspector_events', [
            'source' => 'javascript',
            'severity' => 'error',
            'user_id' => $admin->id,
            'message' => 'ReferenceError: demo is not defined',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inspector.index'))
            ->assertOk()
            ->assertSee('System Inspector')
            ->assertSee('ReferenceError: demo is not defined');

        $response = $this->actingAs($admin)->get(route('admin.inspector.export'));
        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_route_exception_is_recorded_without_request_payload_or_secrets(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'route-owner@example.test');
        $request = Request::create('/admin/does-not-exist?token=secret', 'GET');
        $request->setUserResolver(static fn (): User => $admin);

        app(SystemInspectorRecorder::class)->recordException(
            new NotFoundHttpException('The requested admin route does not exist.'),
            $request,
        );

        $event = SystemInspectorEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('route', $event->source);
        $this->assertSame(404, $event->status_code);
        $this->assertSame('/admin/does-not-exist', $event->url);
        $this->assertStringNotContainsString('token=', (string) $event->url);
    }

    public function test_mobile_runtime_failures_are_sanitized_deduplicated_and_filterable(): void
    {
        $customer = User::query()->create([
            'name' => 'Platform Customer',
            'email' => 'mobile-inspector@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        Sanctum::actingAs($customer);

        $payload = [
            'app' => 'customer',
            'source' => 'api_failure',
            'category' => 'server_failure',
            'severity' => 'error',
            'message' => 'Bearer secret-token failed for customer@example.test',
            'app_version' => '1.0.52',
            'app_build' => '52',
            'platform' => 'android',
            'os_version' => 'Android 15',
            'route' => '/b2b/orders',
            'channel' => 'b2b',
            'path' => '/api/v1/b2b/orders?token=secret-token&scope=all',
            'method' => 'GET',
            'status' => 503,
            'correlation_id' => 'req-safe-503',
            'retry' => 1,
            'stack' => 'token=stack-secret customer@example.test',
        ];

        $this->postJson('/api/v1/runtime/diagnostics', $payload)->assertAccepted();
        $this->postJson('/api/v1/runtime/diagnostics', $payload)->assertAccepted();

        $events = SystemInspectorEvent::query()->where('source', 'customer_app')->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame($customer->id, $event->user_id);
        $this->assertSame(503, $event->status_code);
        $this->assertSame('req-safe-503', $event->correlation_id);
        $this->assertSame('1.0.52', $event->context['app_version']);
        $this->assertSame('52', $event->context['app_build']);
        $this->assertSame('b2b', $event->context['channel']);
        $this->assertStringNotContainsString('secret-token', $event->message);
        $this->assertStringNotContainsString('secret-token', (string) $event->url);
        $this->assertStringNotContainsString('stack-secret', json_encode($event->context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('customer@example.test', json_encode($event->context, JSON_THROW_ON_ERROR));

        $admin = $this->userWithRole('SUPER_ADMIN', 'mobile-filter-admin@example.test');
        $this->actingAs($admin)
            ->get(route('admin.inspector.index', [
                'source' => 'customer_app',
                'version' => '1.0.52',
                'build' => '52',
                'channel' => 'b2b',
            ]))
            ->assertOk()
            ->assertSee('customer_app')
            ->assertSee('req-safe-503');
    }

    public function test_mobile_ingestion_is_write_only_and_driver_source_requires_driver_role(): void
    {
        $customer = User::query()->create([
            'name' => 'Platform Customer',
            'email' => 'customer-write-only@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/runtime/diagnostics', [
            'app' => 'driver',
            'source' => 'flutter_error',
            'severity' => 'error',
            'message' => 'wrong source',
        ])->assertForbidden();

        $this->getJson('/api/v1/runtime/diagnostics')->assertMethodNotAllowed();

        $driver = $this->userWithRole('B2C_DRIVER', 'driver-inspector@example.test');
        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/runtime/diagnostics', [
            'app' => 'driver',
            'source' => 'flutter_error',
            'severity' => 'error',
            'message' => 'Driver runtime failure',
            'channel' => 'b2c',
        ])->assertAccepted();

        $this->assertDatabaseHas('system_inspector_events', [
            'source' => 'driver_app',
            'user_id' => $driver->id,
            'message' => 'Driver runtime failure',
        ]);

        $this->actingAs($driver)->get(route('admin.inspector.index'))->assertForbidden();
    }

    public function test_unhandled_api_server_exception_is_captured_by_global_inspector_hook(): void
    {
        Route::get('/api/v1/_inspector-test-boom', static function (): void {
            throw new \RuntimeException('Synthetic API boom');
        })->middleware('api');

        $this->getJson('/api/v1/_inspector-test-boom')->assertStatus(500);

        $this->assertDatabaseHas('system_inspector_events', [
            'source' => 'server',
            'severity' => 'error',
            'status_code' => 500,
            'message' => 'Synthetic API boom',
        ]);
    }

    public function test_non_platform_admin_cannot_open_or_export_inspector(): void
    {
        $admin = $this->userWithRole('B2B_ADMIN', 'wholesale-admin@example.test');

        $this->actingAs($admin)->get(route('admin.inspector.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.inspector.export'))->assertForbidden();
    }

    public function test_premium_admin_runtime_contains_feedback_labels_previews_and_client_capture(): void
    {
        $partial = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $stores = file_get_contents(resource_path('views/admin/retail-stores.blade.php'));

        $this->assertIsString($partial);
        $this->assertStringContainsString('foodex-premium-form-sweep', $partial);
        $this->assertStringContainsString('foodex-feedback-modal', $partial);
        $this->assertStringContainsString('foodex-image-preview', $partial);
        $this->assertStringContainsString('admin.inspector.client-events', $partial);
        $this->assertStringContainsString('data-open-store-wizard', $stores);
        $this->assertStringContainsString('data-store-create-modal', $stores);
        $this->assertStringContainsString('data-wizard-panel="manager"', $stores);
        $this->assertStringContainsString('data-wizard-panel="review"', $stores);
    }

    private function userWithRole(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
