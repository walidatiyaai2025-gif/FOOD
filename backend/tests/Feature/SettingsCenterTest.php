<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_discover_every_settings_surface_from_one_center(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN', 'en');

        $this->actingAs($user)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('data-settings-center="true"', false)
            ->assertSee('data-setting="mobile"', false)
            ->assertSee('data-setting="driver_location"', false)
            ->assertSee('data-setting="push"', false)
            ->assertSee('data-setting="push_test"', false)
            ->assertSee('data-setting="publishing"', false)
            ->assertSee('data-setting="reviewers"', false)
            ->assertSee('data-setting="app_versions"', false)
            ->assertSee('data-setting="sms"', false)
            ->assertSee('data-setting="assistant"', false)
            ->assertSee('data-setting="translations"', false)
            ->assertSee('data-setting="lookups"', false)
            ->assertSee('data-setting="system_lookups"', false)
            ->assertSee('data-setting="b2b_settings"', false)
            ->assertSee('data-setting="b2b_storefront"', false)
            ->assertSee('data-setting="b2c_settings"', false)
            ->assertSee('data-setting="b2c_storefront"', false)
            ->assertSee('data-setting="system_update"', false)
            ->assertSee('Settings Center')
            ->assertSee('System reference values')
            ->assertSee('B2B wholesale settings')
            ->assertSee('B2C retail settings');
    }

    public function test_restricted_settings_remain_visible_instead_of_being_hidden(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'en');

        $this->actingAs($user)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('data-setting="system_update"', false)
            ->assertSee('data-setting="system_lookups"', false)
            ->assertSee('Restricted')
            ->assertSee('Additional permission required');
    }

    public function test_settings_pages_share_the_administration_settings_switcher(): void
    {
        foreach ([
            'assistant-settings.blade.php',
            'app-versions.blade.php',
            'sms-settings.blade.php',
            'mobile-settings.blade.php',
            'translations.blade.php',
            'system-update.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/admin/'.$view));

            $this->assertIsString($contents);
            $this->assertStringContainsString("_admin-center-switcher", $contents);
            $this->assertStringContainsString("'active'=>'settings'", $contents);
        }
    }

    public function test_mobile_settings_is_split_into_focused_sections(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        foreach (['runtime', 'location', 'push', 'push-test', 'publishing', 'reviewers'] as $section) {
            $this->assertStringContainsString('data-mobile-section="'.$section.'"', $view);
            $this->assertStringContainsString('data-mobile-section-link="'.$section.'"', $view);
        }
    }

    private function userWithRole(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Settings Center Tester',
            'email' => strtolower($roleCode).'-settings@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
