<?php

namespace Tests\Feature;

use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssistantExternalAiClient;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantRuntimeSettings;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
            ->assertSessionHas('provider_test_success');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://ai.example.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer saved-secret')
                && $request['model'] === 'foodex-model';
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
            ->assertSessionHas('provider_test_error');
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
