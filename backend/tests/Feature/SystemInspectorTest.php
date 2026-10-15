<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Models\EnterpriseModule;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use App\Services\SystemInspectorRecorder;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    public function test_client_failure_category_is_preserved_and_urls_are_secret_free(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'inspector-category@example.test');

        $this->actingAs($admin)->postJson(route('admin.inspector.client-events'), [
            'source' => 'fetch',
            'severity' => 'warning',
            'category' => 'validation_rejection',
            'status' => 422,
            'method' => 'PATCH',
            'message' => 'Validation rejected.',
            'url' => 'https://foodex.example.test/api/v1/profile?api_secret=compromised&token=hidden',
            'response_url' => 'https://foodex.example.test/api/v1/profile?api_secret=compromised&token=hidden',
        ])->assertAccepted();

        $event = SystemInspectorEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('validation_rejection', $event->context['category'] ?? null);
        $this->assertSame('https://foodex.example.test/api/v1/profile', $event->url);
        $encoded = json_encode($event->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('api_secret', $encoded);
        $this->assertStringNotContainsString('compromised', $encoded);
        $this->assertStringNotContainsString('token=hidden', $encoded);
    }

    public function test_admin_runtime_uses_current_xsrf_token_and_does_not_report_intentional_aborts(): void
    {
        $partial = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($partial);
        $this->assertStringContainsString("...(xsrfToken ? {'X-XSRF-TOKEN':xsrfToken} : {'X-CSRF-TOKEN':csrfToken})", $partial);
        $this->assertStringContainsString("error?.name === 'AbortError'", $partial);
        $this->assertStringContainsString('!intentionalAbort', $partial);
        $this->assertStringContainsString("'maintenance'", $partial);
        $this->assertStringContainsString("'validation_rejection'", $partial);
        $this->assertStringContainsString("'domain_rejection'", $partial);
        $this->assertStringContainsString('inspectorSuppressedUntil = Date.now() + 60000', $partial);
        $this->assertStringNotContainsString('google-analytics.com/mp/collect', $partial);
        $this->assertStringNotContainsString('api_secret=', $partial);
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

    public function test_enterprise_diagnostics_are_bounded_redacted_and_do_not_boot_disabled_module_probes(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'enterprise-inspector@example.test');

        InspectorForbiddenProbe::$constructed = 0;
        config()->set('enterprise_module_health.probes.sample_optional', [
            InspectorForbiddenProbe::class,
        ]);

        EnterpriseModule::query()->create([
            'code' => 'sample_optional',
            'category' => 'platform',
            'manifest_version' => 1,
            'state' => 'DISABLED',
            'manifest' => ['code' => 'sample_optional', 'version' => 1],
            'state_changed_at' => now(),
        ]);

        foreach (range(1, 26) as $index) {
            EnterpriseOutboxMessage::query()->create([
                'event_id' => (string) Str::uuid(),
                'event_type' => "SafeEvent{$index}",
                'event_version' => 1,
                'payload' => json_encode([
                    'api_secret' => "super-secret-{$index}",
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => now(),
            ]);

            EnterpriseWorkflowRequest::query()->create([
                'workflow_key' => "wf-{$index}",
                'definition_version' => 1,
                'entity_type' => 'Order',
                'entity_id' => (string) $index,
                'scope_type' => 'store',
                'scope_id' => null,
                'requester_id' => $admin->id,
                'status' => 'PENDING',
                'idempotency_key' => "secret-idempotency-{$index}",
                'context' => [
                    'token' => "super-secret-{$index}",
                ],
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.inspector.index'))
            ->assertOk()
            ->assertSee('Enterprise platform')
            ->assertSee('sample_optional')
            ->assertSee('Disabled')
            ->assertSee('SafeEvent26')
            ->assertDontSee('SafeEvent1')
            ->assertSee('wf-26')
            ->assertDontSee('wf-1')
            ->assertDontSee('super-secret')
            ->assertDontSee('secret-idempotency');

        $this->assertSame(0, InspectorForbiddenProbe::$constructed);

        $export = $this->actingAs($admin)->get(route('admin.inspector.export'));
        $export->assertOk();
        $exportContent = $export->streamedContent();
        $this->assertStringContainsString('SafeEvent26', $exportContent);
        $this->assertStringContainsString('wf-26', $exportContent);
        $this->assertStringNotContainsString('super-secret', $exportContent);
        $this->assertStringNotContainsString('secret-idempotency', $exportContent);

        $exportPayload = json_decode($exportContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['event_type', 'status', 'attempt_count', 'available_at', 'failed_at', 'created_at'],
            array_keys($exportPayload['enterprise']['outbox_messages'][0]),
        );
        $this->assertSame(
            ['workflow_key', 'entity_type', 'status', 'completed_at', 'created_at'],
            array_keys($exportPayload['enterprise']['workflow_requests'][0]),
        );

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('Modules & background diagnostics', $html);
        $this->assertSame(
            'Modules & background diagnostics',
            trans('admin.system_inspector_enterprise.title', [], 'en'),
        );
        $this->assertSame(
            'تشخيص الموديولات والخلفية',
            trans('admin.system_inspector_enterprise.title', [], 'ar'),
        );
    }

    public function test_enterprise_diagnostics_render_failing_module_health(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'enterprise-health@example.test');

        config()->set('enterprise_module_health.probes.sample_optional', [
            InspectorFailingProbe::class,
        ]);

        EnterpriseModule::query()->create([
            'code' => 'sample_optional',
            'category' => 'platform',
            'manifest_version' => 1,
            'state' => 'ENABLED',
            'manifest' => ['code' => 'sample_optional', 'version' => 1],
            'enabled_at' => now(),
            'state_changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inspector.index'))
            ->assertOk()
            ->assertSee('sample_optional')
            ->assertSee('Error')
            ->assertDontSee('provider-secret-token');
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

final class InspectorForbiddenProbe implements ModuleHealthProbe
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::healthy('inspector-forbidden-probe');
    }
}

final class InspectorFailingProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('inspector-failing-probe', 'provider-secret-token');
    }
}
