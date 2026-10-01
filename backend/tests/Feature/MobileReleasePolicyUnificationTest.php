<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\MobileAppSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverLocationEnforcementPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileReleasePolicyUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_mobile_settings_prefills_selected_record_and_partial_update_preserves_unrelated_values(): void
    {
        $admin = $this->admin('mobile-release-prefill@example.test');

        MobileAppSetting::query()->create([
            'app' => 'driver',
            'environment' => 'production',
            'display_name' => 'FOODEX Driver Production',
            'android_package_id' => 'com.fiftysolution.foodex.driver',
            'ios_bundle_id' => 'com.fiftysolution.foodex.driver',
            'published_version' => '1.0.40',
            'published_build' => '40',
            'minimum_supported_version' => '1.0.39',
            'recommended_version' => '1.0.40',
            'force_update' => true,
            'maintenance_mode' => true,
            'google_play_url' => 'https://example.test/driver/android',
            'app_store_url' => 'https://example.test/driver/ios',
            'support_url' => 'https://example.test/support',
            'deep_link_config' => ['scheme' => 'foodex-driver'],
            'store_readiness' => ['android' => true, 'ios' => false],
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/mobile?app=driver&environment=production')
            ->assertOk()
            ->assertSee('FOODEX Driver Production')
            ->assertSee('com.fiftysolution.foodex.driver')
            ->assertSee('1.0.40')
            ->assertSee('https://example.test/driver/android')
            ->assertSee('foodex-driver');

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/app', [
                'app' => 'driver',
                'environment' => 'production',
                'display_name' => 'FOODEX Driver Renamed',
            ])
            ->assertRedirect('/admin/settings/mobile?app=driver&environment=production')
            ->assertSessionHasNoErrors();

        $setting = MobileAppSetting::query()
            ->where('app', 'driver')
            ->where('environment', 'production')
            ->firstOrFail();

        $this->assertSame('FOODEX Driver Renamed', $setting->display_name);
        $this->assertSame('1.0.40', $setting->published_version);
        $this->assertSame('https://example.test/driver/android', $setting->google_play_url);
        $this->assertTrue($setting->force_update);
        $this->assertTrue($setting->maintenance_mode);
        $this->assertSame(['scheme' => 'foodex-driver'], $setting->deep_link_config);
        $this->assertSame(['android' => true, 'ios' => false], $setting->store_readiness);
    }

    public function test_invalid_and_missing_driver_update_urls_are_exact_rollout_blockers_and_prevent_enablement(): void
    {
        $admin = $this->admin('mobile-release-blockers@example.test');

        AppVersion::query()->create([
            'app' => 'driver',
            'platform' => 'android',
            'latest_version' => '1.0.40',
            'minimum_supported_version' => '1.0.38',
            'force_update' => true,
            'store_url' => 'ftp://updates.example.test/driver.apk',
            'release_notes' => 'Heartbeat capable',
        ]);

        $readiness = app(DriverLocationEnforcementPolicy::class)->rolloutReadiness();

        $this->assertFalse($readiness['ready']);
        $this->assertContains('android_update_url_invalid', $readiness['blockers']);
        $this->assertContains('ios_policy_missing', $readiness['blockers']);
        $this->assertFalse($readiness['platforms']['android']['update_url_valid']);
        $this->assertFalse($readiness['platforms']['ios']['exists']);

        $this->actingAs($admin)
            ->get('/admin/settings/mobile?app=driver&environment=production')
            ->assertOk()
            ->assertSee('android_update_url_invalid')
            ->assertSee('ios_policy_missing')
            ->assertSee('Current FOODEX release identity');

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/driver-location-policy', [
                'enabled' => '1',
                'freshness_seconds' => 90,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['enabled']);

        $this->assertFalse(app(DriverLocationEnforcementPolicy::class)->enabled());
    }

    public function test_ready_driver_platforms_are_rendered_from_app_version_and_can_enable_enforcement(): void
    {
        $admin = $this->admin('mobile-release-ready@example.test');

        $this->driverPolicy(
            'android',
            latest: '1.0.40',
            minimum: '1.0.39',
            force: true,
            url: 'https://example.test/driver/android',
        );
        $this->driverPolicy(
            'ios',
            latest: '1.0.40',
            minimum: '1.0.38',
            force: false,
            url: 'https://example.test/driver/ios',
        );

        $readiness = app(DriverLocationEnforcementPolicy::class)->rolloutReadiness();
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['blockers']);
        $this->assertTrue($readiness['platforms']['android']['ready']);
        $this->assertTrue($readiness['platforms']['ios']['ready']);

        $this->actingAs($admin)
            ->get('/admin/settings/mobile?app=driver&environment=production')
            ->assertOk()
            ->assertSee('data-driver-policy="android"', false)
            ->assertSee('data-driver-policy="ios"', false)
            ->assertSee('https://example.test/driver/android')
            ->assertSee('https://example.test/driver/ios')
            ->assertSee('READY');

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/driver-location-policy', [
                'enabled' => '1',
                'freshness_seconds' => 120,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(DriverLocationEnforcementPolicy::class)->enabled());
        $this->assertSame(120, app(DriverLocationEnforcementPolicy::class)->freshnessSeconds());
    }

    public function test_app_version_editor_prefills_selected_authoritative_policy(): void
    {
        $admin = $this->admin('mobile-release-app-version@example.test');

        $this->driverPolicy(
            'ios',
            latest: '1.0.40',
            minimum: '1.0.38',
            force: true,
            url: 'https://example.test/driver/ios',
            notes: 'Driver iOS production policy',
        );

        $this->actingAs($admin)
            ->get('/admin/settings/app-versions?app=driver&platform=ios')
            ->assertOk()
            ->assertSee('value="1.0.40"', false)
            ->assertSee('value="1.0.38"', false)
            ->assertSee('value="https://example.test/driver/ios"', false)
            ->assertSee('Driver iOS production policy')
            ->assertSee('authoritative startup/update policy');
    }

    private function driverPolicy(
        string $platform,
        string $latest,
        string $minimum,
        bool $force,
        string $url,
        string $notes = 'Driver rollout policy',
    ): AppVersion {
        return AppVersion::query()->create([
            'app' => 'driver',
            'platform' => $platform,
            'latest_version' => $latest,
            'minimum_supported_version' => $minimum,
            'force_update' => $force,
            'store_url' => $url,
            'release_notes' => $notes,
        ]);
    }

    private function admin(string $email): User
    {
        $admin = User::query()->create([
            'name' => 'Super',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        return $admin;
    }
}
