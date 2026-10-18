<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantProviderGatewayInterface;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssistantExternalAiClient;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantRuntimeSettings;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_runtime_setting_overrides_environment_default_and_persists(): void
    {
        config(['assistant.enabled' => false, 'assistant.read_only' => true]);

        $runtime = app(AssistantRuntimeSettings::class);
        $this->assertFalse($runtime->enabled());
        $this->assertSame('environment', $runtime->snapshot()['source']);

        $runtime->persist(true);

        $this->assertTrue(app(AssistantRuntimeSettings::class)->enabled());
        $this->assertSame('dashboard', app(AssistantRuntimeSettings::class)->snapshot()['source']);
        $this->assertDatabaseHas('settings', [
            'store_id' => null,
            'key' => AssistantRuntimeSettings::ENABLED_KEY,
            'is_secret' => false,
        ]);

        $runtime->persist(false);
        $this->assertFalse(app(AssistantRuntimeSettings::class)->enabled());
    }

    public function test_invalid_persistent_value_fails_closed(): void
    {
        config(['assistant.enabled' => true]);

        Setting::query()->create([
            'store_id' => null,
            'key' => AssistantRuntimeSettings::ENABLED_KEY,
            'value' => json_encode('unexpected-value', JSON_THROW_ON_ERROR),
            'is_secret' => false,
        ]);

        $this->assertFalse(app(AssistantRuntimeSettings::class)->enabled());
    }

    public function test_settings_manager_can_enable_and_disable_assistant_and_change_is_audited(): void
    {
        config(['assistant.enabled' => false, 'assistant.read_only' => true]);
        $user = $this->settingsManager();

        $this->actingAs($user)
            ->get('/admin/settings/assistant')
            ->assertOk()
            ->assertSee('FOODEX Assistant Settings');

        $this->actingAs($user)
            ->put('/admin/settings/assistant', ['enabled' => 1])
            ->assertRedirect(route('admin.assistant-settings.index'));

        $this->assertTrue(app(AssistantRuntimeSettings::class)->enabled());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'assistant.settings.updated',
        ]);

        $this->actingAs($user)
            ->put('/admin/settings/assistant', ['enabled' => 0])
            ->assertRedirect(route('admin.assistant-settings.index'));

        $this->assertFalse(app(AssistantRuntimeSettings::class)->enabled());
    }

    public function test_assistant_settings_page_exposes_fillable_external_ai_configuration(): void
    {
        $user = $this->settingsManager();

        $this->actingAs($user)
            ->get('/admin/settings/assistant')
            ->assertOk()
            ->assertSee('data-assistant-provider-settings', false)
            ->assertSee('data-assistant-model-policy', false)
            ->assertSee('name="api_url"', false)
            ->assertSee('name="model"', false)
            ->assertSee('name="api_key"', false)
            ->assertSee('name="timeout_seconds"', false)
            ->assertSee(route('admin.assistant-settings.provider.update'), false)
            ->assertSee(route('admin.assistant-settings.provider.test'), false);
    }

    public function test_settings_manager_can_save_provider_and_api_key_is_encrypted_at_rest(): void
    {
        $user = $this->settingsManager();

        $this->actingAs($user)
            ->put('/admin/settings/assistant/provider', [
                'provider_enabled' => 1,
                'api_url' => 'https://ai.example.test/v1/chat/completions',
                'model' => 'foodex-model',
                'api_key' => 'secret-provider-key',
                'timeout_seconds' => 25,
            ])
            ->assertRedirect(route('admin.assistant-settings.index'));

        $settings = app(AssistantProviderSettings::class)->snapshot();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('https://ai.example.test/v1/chat/completions', $settings['api_url']);
        $this->assertSame('foodex-model', $settings['model']);
        $this->assertSame([], $settings['fallback_models']);
        $this->assertTrue($settings['api_key_configured']);

        $secret = Setting::query()
            ->whereNull('store_id')
            ->where('key', AssistantProviderSettings::API_KEY_KEY)
            ->firstOrFail();

        $this->assertTrue((bool) $secret->is_secret);
        $this->assertNotSame('secret-provider-key', $secret->value);

        $encryptedValue = json_decode((string) $secret->value, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsString($encryptedValue);
        $this->assertSame('secret-provider-key', Crypt::decryptString($encryptedValue));
        $this->assertSame('secret-provider-key', app(AssistantProviderSettings::class)->apiKey());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'assistant.provider.updated',
        ]);

        $this->actingAs($user)
            ->get('/admin/settings/assistant')
            ->assertOk()
            ->assertSee('A key is already saved encrypted. Leave this blank to keep it.')
            ->assertDontSee('secret-provider-key');
    }

    public function test_saved_provider_connection_can_be_tested_without_resubmitting_secret(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => "<think>Checking provider connectivity.</think>\n```text\nFOODEX_OK\n```"],
                ]],
            ], 200),
        ]);

        $user = $this->settingsManager();
        app(AssistantProviderSettings::class)->persist(
            [
                'enabled' => true,
                'api_url' => 'https://ai.example.test/v1/chat/completions',
                'model' => 'foodex-model',
                'timeout_seconds' => 20,
            ],
            'saved-secret',
        );

        $this->actingAs($user)
            ->post('/admin/settings/assistant/provider/test')
            ->assertRedirect(route('admin.assistant-settings.index'))
            ->assertSessionHas('provider_test_success')
            ->assertSessionHas('provider_probe');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://ai.example.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer saved-secret')
                && $request['model'] === 'foodex-model';
        });
    }

    public function test_provider_probe_separates_reachability_from_agent_capability(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'FOODEX_OK'],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"ok":true,"contract":"foodex"}'],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_probe',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'foodex_capability_probe',
                                    'arguments' => '{"ok":true}',
                                ],
                            ]],
                        ],
                    ]],
                ], 200),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
            'context_window_tokens' => 32768,
            'max_tokens' => 1024,
        ]);

        $result = app(AssistantExternalAiClient::class)->probe(
            [
                'api_url' => 'https://ai.example.test/v1/chat/completions',
                'model' => 'qwen3:4b',
                'timeout_seconds' => 20,
            ],
            null,
        );

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['agent_capable']);
        $this->assertTrue($result['capabilities']['chat']);
        $this->assertTrue($result['capabilities']['structured_json']);
        $this->assertTrue($result['capabilities']['tool_calls']);
        $this->assertTrue($result['capabilities']['budget_contract']);
        $this->assertSame(32768, $result['capabilities']['context_window_tokens']);
        $this->assertSame(1024, $result['capabilities']['max_output_tokens']);
    }

    public function test_typed_provider_gateway_exposes_capabilities_health_and_container_binding(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'FOODEX_OK'],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"ok":true,"contract":"foodex"}'],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_probe',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'foodex_capability_probe',
                                    'arguments' => '{"ok":true}',
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'FOODEX_OK'],
                    ]],
                ], 200),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'fallback_models' => ['qwen3:1.7b'],
            'timeout_seconds' => 20,
            'context_window_tokens' => 32768,
            'max_tokens' => 1024,
        ]);

        $gateway = app(AssistantProviderGatewayInterface::class);
        $capabilities = $gateway->capabilities();

        $this->assertSame('openai_compatible', $capabilities->provider);
        $this->assertSame('qwen3:4b', $capabilities->model);
        $this->assertSame(['qwen3:1.7b'], $capabilities->fallbackModels);
        $this->assertTrue($capabilities->chat);
        $this->assertTrue($capabilities->structuredJson);
        $this->assertTrue($capabilities->toolCalls);
        $this->assertTrue($capabilities->reachable);
        $this->assertTrue($capabilities->healthy);
        $this->assertTrue($capabilities->agentCapable);

        $health = $gateway->health();

        $this->assertTrue($health->enabled);
        $this->assertTrue($health->reachable);
        $this->assertTrue($health->healthy);
        $this->assertSame(200, $health->statusCode);
        $this->assertNull($health->failureReason);
    }

    public function test_typed_provider_complete_normalizes_payload_tool_calls_and_usage(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "<think>Reason privately.</think>\n```json\n{\"answer\":\"ok\"}\n```",
                        'tool_calls' => [[
                            'id' => 'call_orders',
                            'type' => 'function',
                            'function' => [
                                'name' => 'orders_lookup',
                                'arguments' => '{"order_id":42}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 30,
                    'total_tokens' => 150,
                ],
            ], 200),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
            'context_window_tokens' => 32768,
            'max_tokens' => 1024,
        ]);

        $result = app(AssistantProviderGatewayInterface::class)->complete(
            new AssistantProviderRequest(
                systemInstructions: 'You are a bounded FOODEX planner.',
                taskInstructions: 'Return a structured proposal only.',
                userContent: 'Find order 42.',
                context: ['store_id' => 7],
                allowedTools: [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'orders_lookup',
                        'description' => 'Read one authorized order.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'order_id' => ['type' => 'integer'],
                            ],
                            'required' => ['order_id'],
                        ],
                    ],
                ]],
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                    ],
                    'required' => ['answer'],
                ],
                maxTokens: 512,
                timeoutSeconds: 10,
                correlationId: 'test-correlation-2210',
                useCase: 'agent_planning',
            ),
        );

        $this->assertTrue($result->ok);
        $this->assertSame('qwen3:4b', $result->model);
        $this->assertSame('{"answer":"ok"}', $result->text);
        $this->assertSame(['answer' => 'ok'], $result->structuredPayload);
        $this->assertSame('orders_lookup', $result->toolCalls[0]['name']);
        $this->assertSame(['order_id' => 42], $result->toolCalls[0]['arguments']);
        $this->assertSame('tool_calls', $result->finishReason);
        $this->assertSame(150, $result->usage['total_tokens']);
        $this->assertFalse($result->fallback);

        Http::assertSent(function ($request): bool {
            return $request['model'] === 'qwen3:4b'
                && $request['max_tokens'] === 512
                && $request['tools'][0]['function']['name'] === 'orders_lookup'
                && str_contains($request['messages'][0]['content'], 'Server-provided bounded context')
                && str_contains($request['messages'][0]['content'], 'Required response schema');
        });
    }

    public function test_external_ai_route_accepts_qwen_thinking_and_fenced_json(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "<think>I should classify this request.</think>\n```json\n{\"intent\":\"orders.lookup\",\"confidence\":0.97,\"message\":\"سأبحث عن الطلب 123.\",\"entities\":{\"order_id\":123}}\n```",
                    ],
                ]],
            ], 200),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
        ]);

        $result = app(AssistantExternalAiClient::class)->route(
            new AssistantBrainRequest(
                message: 'هات الطلب 123',
                locale: 'ar',
            ),
        );

        $this->assertNotNull($result);
        $this->assertSame('orders.lookup', $result['intent']);
        $this->assertSame(0.97, $result['confidence']);
        $this->assertSame('سأبحث عن الطلب 123.', $result['message']);
        $this->assertSame(['order_id' => 123], $result['entities']);
    }

    public function test_provider_connection_rejects_non_sentinel_model_reply(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'The provider is reachable, but I will not follow the probe contract.'],
                ]],
            ], 200),
        ]);

        $user = $this->settingsManager();
        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
        ]);

        $this->actingAs($user)
            ->post('/admin/settings/assistant/provider/test')
            ->assertRedirect(route('admin.assistant-settings.index'))
            ->assertSessionHas('provider_test_error')
            ->assertSessionHas('provider_probe', fn (array $probe): bool => $probe['reachable'] && ! $probe['agent_capable']);
    }

    public function test_provider_gateway_falls_back_after_primary_5xx(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::sequence()
                ->push([], 503)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => '{"intent":"orders.lookup","confidence":0.9,"message":"fallback","entities":{"order_id":77}}',
                        ],
                    ]],
                ], 200),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'fallback_models' => ['qwen3:1.7b'],
            'timeout_seconds' => 20,
            'retry_attempts' => 0,
        ]);

        $result = app(AssistantExternalAiClient::class)->route(
            new AssistantBrainRequest(message: 'order 77', locale: 'en'),
        );

        $this->assertNotNull($result);
        $this->assertSame(77, $result['entities']['order_id']);
        $models = collect(Http::recorded())
            ->map(static fn (array $entry): mixed => $entry[0]['model'])
            ->values()
            ->all();
        $this->assertSame(['qwen3:4b', 'qwen3:1.7b'], $models);
    }

    public function test_provider_gateway_does_not_retry_4xx(): void
    {
        Http::fake([
            'https://ai.example.test/*' => Http::response([], 401),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
            'retry_attempts' => 2,
        ]);

        $this->assertNull(app(AssistantExternalAiClient::class)->route(
            new AssistantBrainRequest(message: 'hello', locale: 'en'),
        ));

        Http::assertSentCount(1);
    }

    public function test_provider_gateway_fails_closed_on_timeout(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('simulated timeout');
        });

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 3,
            'retry_attempts' => 0,
        ]);

        $this->assertNull(app(AssistantExternalAiClient::class)->route(
            new AssistantBrainRequest(message: 'hello', locale: 'en'),
        ));
    }

    public function test_provider_telemetry_redacts_secrets_and_url_query(): void
    {
        Log::spy();
        Http::fake([
            'https://ai.example.test/*' => Http::response([], 503),
        ]);

        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions?token=url-secret',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 20,
            'retry_attempts' => 0,
        ], 'secret-provider-key');

        $this->assertNull(app(AssistantExternalAiClient::class)->route(
            new AssistantBrainRequest(message: 'hello', locale: 'en'),
        ));

        Log::shouldHaveReceived('info')
            ->withArgs(static function (string $message, array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'assistant.provider.request'
                    && ! str_contains($encoded, 'secret-provider-key')
                    && ! str_contains($encoded, 'url-secret')
                    && ($context['endpoint'] ?? null) === 'https://ai.example.test/v1/chat/completions';
            })
            ->atLeast()
            ->once();
    }

    public function test_dashboard_cannot_enable_assistant_when_read_only_fence_is_off(): void
    {
        config(['assistant.enabled' => false, 'assistant.read_only' => false]);
        $user = $this->settingsManager();

        $this->actingAs($user)
            ->from('/admin/settings/assistant')
            ->put('/admin/settings/assistant', ['enabled' => 1])
            ->assertRedirect('/admin/settings/assistant')
            ->assertSessionHasErrors('enabled');

        $this->assertDatabaseMissing('settings', [
            'store_id' => null,
            'key' => AssistantRuntimeSettings::ENABLED_KEY,
        ]);
    }

    public function test_unauthorized_user_cannot_view_or_change_assistant_setting(): void
    {
        $user = User::query()->create([
            'name' => 'No Settings Permission',
            'email' => 'assistant-no-settings@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/admin/settings/assistant')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/assistant', ['enabled' => 1])->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/assistant/provider', [
            'provider_enabled' => 0,
            'api_url' => '',
            'model' => '',
            'timeout_seconds' => 20,
        ])->assertForbidden();
        $this->actingAs($user)->post('/admin/settings/assistant/provider/test')->assertForbidden();
    }

    public function test_global_enable_does_not_grant_assistant_use_permission(): void
    {
        config(['assistant.enabled' => false, 'assistant.read_only' => true]);
        $user = $this->settingsManager();

        $this->actingAs($user)
            ->put('/admin/settings/assistant', ['enabled' => 1])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->hasPermission('assistant.use'));

        $this->actingAs($user)
            ->get('/admin/assistant/bootstrap')
            ->assertForbidden();
    }

    public function test_read_only_guard_remains_mandatory_even_when_dashboard_setting_is_enabled(): void
    {
        config(['assistant.enabled' => false, 'assistant.read_only' => false]);
        app(AssistantRuntimeSettings::class)->persist(true);
        $user = $this->userWithRole('SUPER_ADMIN', 'read-only-guard');

        $this->actingAs($user)
            ->get('/admin/assistant/bootstrap')
            ->assertStatus(503);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-foodex-assistant', false);
    }

    private function settingsManager(): User
    {
        $role = Role::query()->create([
            'code' => 'ASSISTANT_SETTINGS_MANAGER',
            'name' => 'Assistant Settings Manager',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $permission = Permission::query()->where('code', 'settings.manage')->firstOrFail();
        $role->permissions()->attach($permission);

        $user = User::query()->create([
            'name' => 'Assistant Settings Manager',
            'email' => 'assistant-settings-manager@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }

    private function userWithRole(string $roleCode, string $suffix): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$suffix.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
