<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssistantRuntimeSettings;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
