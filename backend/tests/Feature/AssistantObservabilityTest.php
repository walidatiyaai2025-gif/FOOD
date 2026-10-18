<?php

namespace Tests\Feature;

use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AssistantObservability;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantExternalAiClient;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config([
            'assistant.observability.enabled' => true,
            'assistant.observability.retention_days' => 14,
            'assistant.observability.admin_window_hours' => 24,
        ]);
    }

    public function test_trace_events_correlate_and_sensitive_metadata_is_redacted_before_storage(): void
    {
        $observability = app(AssistantObservability::class);

        $observability->record('trace-safe-2228', 'turn.started', [
            'actor_user_id' => 10,
            'store_id' => 7,
            'phase' => 'turn',
            'outcome' => 'started',
            'metadata' => [
                'attempt' => 1,
                'api_key' => 'provider-secret',
                'prompt' => 'private user request',
                'customer_email' => 'customer@example.test',
                'nested' => [
                    'password' => 'hidden',
                    'safe_code' => 'OK',
                ],
            ],
        ]);

        $observability->record('trace-safe-2228', 'turn.completed', [
            'actor_user_id' => 10,
            'store_id' => 7,
            'phase' => 'turn',
            'outcome' => 'COMPLETE',
            'latency_ms' => 87,
        ]);

        $rows = DB::table('assistant_observability_events')
            ->where('trace_id', 'trace-safe-2228')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $metadata = json_decode((string) $rows[0]->metadata, true, flags: JSON_THROW_ON_ERROR);
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $metadata['attempt']);
        $this->assertSame('[REDACTED]', $metadata['api_key']);
        $this->assertSame('[REDACTED]', $metadata['prompt']);
        $this->assertSame('[REDACTED]', $metadata['customer_email']);
        $this->assertSame('[REDACTED]', $metadata['nested']['password']);
        $this->assertSame('OK', $metadata['nested']['safe_code']);
        $this->assertStringNotContainsString('provider-secret', $encoded);
        $this->assertStringNotContainsString('private user request', $encoded);
        $this->assertStringNotContainsString('customer@example.test', $encoded);
    }

    public function test_retention_prunes_only_expired_observability_rows(): void
    {
        $now = now();

        DB::table('assistant_observability_events')->insert([
            [
                'trace_id' => 'trace-expired',
                'event_name' => 'turn.completed',
                'phase' => 'turn',
                'outcome' => 'COMPLETE',
                'fallback' => false,
                'created_at' => $now->copy()->subDays(15),
                'updated_at' => $now->copy()->subDays(15),
            ],
            [
                'trace_id' => 'trace-current',
                'event_name' => 'turn.completed',
                'phase' => 'turn',
                'outcome' => 'COMPLETE',
                'fallback' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $deleted = app(AssistantObservability::class)->prune();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('assistant_observability_events', ['trace_id' => 'trace-expired']);
        $this->assertDatabaseHas('assistant_observability_events', ['trace_id' => 'trace-current']);
    }

    public function test_trace_inspection_and_export_require_settings_manage(): void
    {
        app(AssistantObservability::class)->record('trace-role-2228', 'turn.completed', [
            'phase' => 'turn',
            'outcome' => 'COMPLETE',
            'latency_ms' => 55,
        ]);

        $viewer = $this->userWithPermission('settings.view', 'viewer');

        $this->actingAs($viewer)
            ->get('/admin/settings/assistant?trace=trace-role-2228')
            ->assertOk()
            ->assertSee('AI Operations Center')
            ->assertDontSee('trace-role-2228');

        $this->actingAs($viewer)
            ->get('/admin/settings/assistant/diagnostics.csv')
            ->assertForbidden();

        $manager = $this->userWithPermission('settings.manage', 'manager');

        $this->actingAs($manager)
            ->get('/admin/settings/assistant?trace=trace-role-2228')
            ->assertOk()
            ->assertSee('trace-role-2228');

        $this->actingAs($manager)
            ->get('/admin/settings/assistant/diagnostics.csv')
            ->assertOk();
    }

    public function test_provider_failure_telemetry_keeps_trace_and_redacts_endpoint_secrets(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([], 503),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions?token=url-secret',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
            'retry_attempts' => 0,
        ], 'provider-secret');

        $result = app(AssistantExternalAiClient::class)->complete(new AssistantProviderRequest(
            systemInstructions: 'System instructions.',
            taskInstructions: 'Task instructions.',
            userContent: 'Private user content.',
            correlationId: 'trace-provider-failure-2228',
            useCase: 'agent_planning',
        ));

        $this->assertFalse($result->ok);

        $row = DB::table('assistant_observability_events')
            ->where('trace_id', 'trace-provider-failure-2228')
            ->where('event_name', 'provider.transport')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('http_error', $row->outcome);

        $stored = json_encode((array) $row, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('url-secret', $stored);
        $this->assertStringNotContainsString('provider-secret', $stored);
        $this->assertStringNotContainsString('Private user content', $stored);
    }

    public function test_summary_reports_bounded_provider_tool_and_fallback_metrics(): void
    {
        $observability = app(AssistantObservability::class);

        $observability->record('trace-metrics-2228', 'turn.started', [
            'phase' => 'turn',
            'outcome' => 'started',
        ]);
        $observability->record('trace-metrics-2228', 'provider.transport', [
            'phase' => 'provider',
            'provider' => 'openai_compatible',
            'model' => 'qwen3:4b',
            'outcome' => 'success',
            'latency_ms' => 120,
            'fallback' => true,
        ]);
        $observability->record('trace-metrics-2228', 'provider.result', [
            'phase' => 'provider',
            'provider' => 'openai_compatible',
            'model' => 'qwen3:4b',
            'outcome' => 'success',
            'latency_ms' => 120,
            'fallback' => true,
            'total_tokens' => 321,
        ]);
        $observability->record('trace-metrics-2228', 'tool.completed', [
            'phase' => 'tool',
            'tool_key' => 'orders.lookup',
            'outcome' => 'success',
            'latency_ms' => 15,
        ]);

        $summary = $observability->summary();

        $this->assertTrue($summary['available']);
        $this->assertSame(1, $summary['request_count']);
        $this->assertSame(1, $summary['provider_requests']);
        $this->assertSame(0, $summary['provider_errors']);
        $this->assertSame(120, $summary['avg_provider_latency_ms']);
        $this->assertSame(1, $summary['tool_calls']);
        $this->assertSame(0, $summary['tool_errors']);
        $this->assertSame(100.0, $summary['fallback_rate_percent']);
        $this->assertSame(321, $summary['total_tokens']);
    }

    private function userWithPermission(string $permissionCode, string $suffix): User
    {
        $role = Role::query()->create([
            'code' => 'ASSISTANT_OBSERVABILITY_'.strtoupper($suffix),
            'name' => 'Assistant Observability '.ucfirst($suffix),
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', $permissionCode)->firstOrFail(),
        );

        $user = User::query()->create([
            'name' => 'Assistant Observability '.ucfirst($suffix),
            'email' => 'assistant-observability-'.$suffix.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
