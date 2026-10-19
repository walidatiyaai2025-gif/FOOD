<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantRuntimeSettings;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_settings_viewer_can_open_ai_platform_overview_without_secret_exposure(): void
    {
        config([
            'assistant.enabled' => false,
            'assistant.read_only' => true,
            'assistant.security.provider_enabled' => true,
            'assistant.security.tools_enabled' => true,
            'assistant.security.write_actions_enabled' => false,
        ]);

        app(AssistantRuntimeSettings::class)->persist(true);
        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions?token=overview-url-secret',
            'model' => 'qwen3:4b',
            'fallback_models' => ['qwen3:1.7b'],
            'timeout_seconds' => 20,
        ], 'overview-provider-secret');

        $response = $this->actingAs($this->settingsViewer())
            ->get(route('admin.assistant-overview.index'));

        $response
            ->assertOk()
            ->assertSee('FOODEX AI Platform Overview')
            ->assertSee('data-foodex-utility="assistant-overview"', false)
            ->assertSee('qwen3:4b')
            ->assertSee('Demand forecasting & replenishment')
            ->assertSee(route('admin.assistant-settings.index'), false)
            ->assertSee(route('admin.assistant-intelligence.index'), false)
            ->assertDontSee('overview-provider-secret')
            ->assertDontSee('overview-url-secret')
            ->assertDontSee('https://ai.example.test/v1/chat/completions');
    }

    public function test_ai_overview_is_discoverable_from_existing_assistant_surfaces(): void
    {
        $user = $this->settingsViewer();

        $this->actingAs($user)
            ->get(route('admin.assistant-settings.index'))
            ->assertOk()
            ->assertSee(route('admin.assistant-overview.index'), false);

        $this->actingAs($user)
            ->get(route('admin.assistant-intelligence.index'))
            ->assertOk()
            ->assertSee(route('admin.assistant-overview.index'), false);
    }

    public function test_user_without_settings_permission_cannot_open_ai_overview(): void
    {
        $user = User::query()->create([
            'name' => 'No AI Overview Permission',
            'email' => 'no-ai-overview@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.assistant-overview.index'))
            ->assertForbidden();
    }

    private function settingsViewer(): User
    {
        $role = Role::query()->create([
            'code' => 'ASSISTANT_OVERVIEW_VIEWER',
            'name' => 'Assistant Overview Viewer',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $permission = Permission::query()->where('code', 'settings.view')->firstOrFail();
        $role->permissions()->attach($permission);

        $user = User::query()->create([
            'name' => 'Assistant Overview Viewer',
            'email' => 'assistant-overview-viewer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
