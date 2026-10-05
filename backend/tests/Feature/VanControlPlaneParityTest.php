<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\MobileAppSetting;
use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson('/api/v1/mobile/runtime?app=van&environment=production&locale=en')
            ->assertOk()
            ->assertJsonPath('data.app', 'van')
            ->assertJsonPath('data.maintenance_message', 'Van maintenance');
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

    public function test_van_download_uses_current_release_authority(): void
    {
        $admin = $this->admin();

        SystemVersion::query()->create([
            'version' => '9.8.7',
            'installed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.van.download'))
            ->assertRedirect('https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Van.apk');
    }

    public function test_preview_center_exposes_real_van_runtime_contract_without_fake_impersonation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/app-preview?app=van')
            ->assertOk()
            ->assertSee('value="van"', false)
            ->assertSee('preview-van-readonly', false)
            ->assertSee('/preview/van/', false);
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
