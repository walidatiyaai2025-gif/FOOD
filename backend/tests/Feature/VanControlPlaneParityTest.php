<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VanControlPlaneParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_mobile_settings_and_runtime_accept_van_as_first_class_app(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/mobile/app', [
            'app' => 'van',
            'environment' => 'production',
            'display_name' => 'FOODEX Van',
            'published_version' => '0.1.0',
            'published_build' => '1',
            'minimum_supported_version' => '0.1.0',
            'recommended_version' => '0.1.0',
            'maintenance_mode' => '1',
            'maintenance_message_en' => 'Van maintenance',
        ])->assertRedirect('/admin/settings/mobile?app=van&environment=production')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mobile_app_settings', [
            'app' => 'van',
            'environment' => 'production',
            'display_name' => 'FOODEX Van',
        ]);

        $this->actingAs($admin)->put('/admin/settings/mobile/push', [
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'enabled' => '0',
            'default_sound' => 'default',
            'default_channel' => 'foodex_van_high_priority',
            'default_icon' => 'ic_notification',
            'default_category' => 'operations',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('push_provider_settings', [
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'default_channel' => 'foodex_van_high_priority',
        ]);

        $this->getJson('/api/v1/mobile/runtime?app=van&environment=production&locale=en')
            ->assertOk()
            ->assertJsonPath('data.app', 'van')
            ->assertJsonPath('data.maintenance_message', 'Van maintenance');
    }

    public function test_authorized_van_can_register_for_dashboard_managed_push(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/push/devices', [
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'van-fcm-token-1',
            'install_id' => 'van-install-1',
            'locale' => 'en',
        ])->assertCreated()
            ->assertJsonPath('data.app', 'van')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.environment', 'production');

        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => $admin->id,
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'install_id' => 'van-install-1',
            'revoked_at' => null,
        ]);
    }

    public function test_app_version_policy_can_represent_van(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/settings/app-versions', [
            'app' => 'van',
            'platform' => 'android',
            'latest_version' => '0.1.0',
            'minimum_supported_version' => '0.1.0',
            'force_update' => '0',
            'store_url' => 'https://example.test/van/android',
            'release_notes' => 'Van pilot',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('app_versions', [
            'app' => 'van',
            'platform' => 'android',
            'latest_version' => '0.1.0',
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/app-versions?app=van&platform=android')
            ->assertOk()
            ->assertSee('Van')
            ->assertSee('0.1.0');
    }

    public function test_van_download_defers_release_mirroring_to_the_administration_hub(): void
    {
        Queue::fake();
        $admin = $this->admin();

        SystemVersion::query()->create([
            'version' => '9.8.7',
            'installed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.van.download'))
            ->assertRedirect(route('admin.administration.index', ['download_app' => 'van']));

        Queue::assertNothingPushed();
    }

    public function test_preview_center_exposes_real_van_runtime_contract_without_fake_impersonation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/app-preview?app=van')
            ->assertOk()
            ->assertSee('value="van"', false)
            ->assertSee('preview-van-readonly', false)
            ->assertSee('preview\\/van\\/', false);
    }

    private function admin(): User
    {
        $admin = User::query()->create([
            'name' => 'Super',
            'email' => 'van-control-plane@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }
}
