<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppPreviewDeviceProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_preview_profiles_define_real_portrait_viewport_semantics(): void
    {
        $profiles = config('app_preview.device_profiles');

        foreach ([
            'android_common',
            'android_small',
            'android_large',
            'iphone_common',
            'narrow_stress',
        ] as $key) {
            $this->assertArrayHasKey($key, $profiles);
            $profile = $profiles[$key];

            $this->assertGreaterThanOrEqual(320, $profile['width']);
            $this->assertGreaterThan($profile['width'], $profile['height']);
            $this->assertSame('portrait', $profile['orientation']);
            $this->assertArrayHasKey('safe_area', $profile);
            $this->assertArrayHasKey('text_scale', $profile);
            $this->assertArrayHasKey('view_insets', $profile);
        }

        $this->assertSame(59, $profiles['iphone_common']['safe_area']['top']);
        $this->assertSame(34, $profiles['iphone_common']['safe_area']['bottom']);
        $this->assertGreaterThan(1, $profiles['narrow_stress']['text_scale']);
    }

    public function test_dashboard_serializes_full_profile_contract_to_shared_runtime(): void
    {
        $user = User::query()->create([
            'name' => 'Preview Device Admin',
            'email' => 'preview-device-admin@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        config()->set('app_preview.runtimes.customer.url', 'https://preview.example/customer');
        config()->set('app_preview.runtimes.customer.contract_version', 'shared-flutter-v1');
        config()->set('app_preview.runtimes.customer.allowed_origin', 'https://preview.example');

        $html = $this->actingAs($user)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'android_common',
            'android_small',
            'android_large',
            'iphone_common',
            'narrow_stress',
        ] as $key) {
            $this->assertStringContainsString('value="'.$key.'"', $html);
        }

        foreach ([
            'data-height=',
            'data-safe-top=',
            'data-safe-bottom=',
            'data-orientation=',
            'data-text-scale=',
            'data-view-inset-bottom=',
            'safe_area:',
            'view_insets:',
            'text_scale:',
            "orientation: device?.selectedOptions?.[0]?.dataset?.orientation || 'portrait'",
            '--preview-device-height',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString('390×844', $html);
    }
}
