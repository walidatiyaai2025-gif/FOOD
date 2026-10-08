<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_dashboard_apk_links_redirect_to_foodex_domain_latest_routes(): void
    {
        $admin = $this->superAdmin();

        SystemVersion::query()->create([
            'version' => '9.8.7',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.customer.download'))
            ->assertRedirect(route('public.mobile-apps.latest', ['app' => 'customer']));

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.driver.download'))
            ->assertRedirect(route('public.mobile-apps.latest', ['app' => 'driver']));
    }


    public function test_public_versioned_customer_apk_is_served_as_verified_attachment_from_foodex_route(): void
    {
        $payload = 'verified-apk-bytes';
        $sha256 = hash('sha256', $payload);

        Http::fake([
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/LATEST_RELEASE.json' => Http::response([
                'version' => '9.8.7',
                'customer' => [
                    'file' => 'FOODEX-Customer-9.8.7.apk',
                    'bytes' => strlen($payload),
                    'sha256' => $sha256,
                ],
            ]),
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Customer-9.8.7.apk' => Http::response($payload),
        ]);

        $this->get('/downloads/apps/customer/9.8.7.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('x-foodex-release-version', '9.8.7')
            ->assertHeader('x-foodex-artifact-sha256', $sha256)
            ->assertDownload('FOODEX-Customer-9.8.7.apk');
    }

    public function test_public_latest_customer_apk_uses_installed_release_version(): void
    {
        SystemVersion::query()->create([
            'version' => '9.8.7',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        $payload = 'latest-apk';
        $sha256 = hash('sha256', $payload);

        Http::fake([
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/LATEST_RELEASE.json' => Http::response([
                'version' => '9.8.7',
                'customer' => [
                    'file' => 'FOODEX-Customer-9.8.7.apk',
                    'bytes' => strlen($payload),
                    'sha256' => $sha256,
                ],
            ]),
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Customer-9.8.7.apk' => Http::response($payload),
        ]);

        $this->get('/downloads/apps/customer/latest.apk')
            ->assertOk()
            ->assertDownload('FOODEX-Customer-9.8.7.apk');
    }

    public function test_download_rejects_release_bytes_that_do_not_match_manifest_checksum(): void
    {
        Http::fake([
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/LATEST_RELEASE.json' => Http::response([
                'version' => '9.8.7',
                'customer' => [
                    'file' => 'FOODEX-Customer-9.8.7.apk',
                    'bytes' => 3,
                    'sha256' => str_repeat('a', 64),
                ],
            ]),
            'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v9.8.7/FOODEX-Customer-9.8.7.apk' => Http::response('bad'),
        ]);

        $this->get('/downloads/apps/customer/9.8.7.apk')
            ->assertStatus(502);
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
