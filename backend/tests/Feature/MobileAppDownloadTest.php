<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAppDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_sidebar_has_preview_and_version_locked_customer_and_driver_downloads(): void
    {
        $admin = $this->superAdmin();

        $applications = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'applications');

        $this->assertIsArray($applications);
        $this->assertSame('admin.nav_groups.applications', $applications['label']);
        $this->assertSame(
            ['app_preview', 'mobile_customer_download', 'mobile_driver_download', 'mobile_van_download'],
            collect($applications['children'])->pluck('key')->values()->all(),
        );
        $this->assertSame(
            ['admin.app-preview.index', 'admin.mobile-apps.customer.download', 'admin.mobile-apps.driver.download', 'admin.mobile-apps.van.download'],
            collect($applications['children'])->pluck('route')->values()->all(),
        );
    }

    public function test_customer_and_driver_apk_redirects_use_installed_dashboard_version(): void
    {
        $admin = $this->superAdmin();

        SystemVersion::query()->create([
            'version' => '9.8.7',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.customer.download'))
            ->assertRedirect('https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Customer.apk');

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.driver.download'))
            ->assertRedirect('https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Driver.apk');
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'mobile-downloads@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
