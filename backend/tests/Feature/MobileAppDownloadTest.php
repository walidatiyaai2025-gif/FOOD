<?php

namespace Tests\Feature;

use App\Jobs\MirrorMobileReleaseArtifacts;
use App\Models\AppVersion;
use App\Models\MobileReleaseArtifact;
use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use App\Services\MobileReleaseArtifactMirror;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobileAppDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        File::deleteDirectory(storage_path('app/private/mobile-releases/9.8.7'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/mobile-releases/9.8.7'));
        parent::tearDown();
    }

    public function test_super_admin_sidebar_has_preview_and_version_locked_customer_driver_and_van_downloads(): void
    {
        $admin = $this->superAdmin();

        $applications = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'applications');

        $this->assertIsArray($applications);
        $this->assertSame(
            ['app_preview', 'mobile_customer_download', 'mobile_driver_download', 'mobile_van_download'],
            collect($applications['children'])->pluck('key')->values()->all(),
        );
        $this->assertSame(
            ['admin.app-preview.index', 'admin.mobile-apps.customer.download', 'admin.mobile-apps.driver.download', 'admin.mobile-apps.van.download'],
            collect($applications['children'])->pluck('route')->values()->all(),
        );
    }

    public function test_dashboard_ready_apk_link_redirects_to_versioned_local_download(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();
        $this->installVersion('9.9.9');
        $this->setAndroidVersion('customer', '9.8.7');
        $this->readyArtifact('customer', 'Customer', 'customer-local-bytes');

        $this->actingAs($admin)
            ->get(route('admin.mobile-apps.customer.download'))
            ->assertRedirect(route('public.mobile-apps.versioned', [
                'app' => 'customer',
                'version' => '9.8.7',
            ]));
    }

    public function test_public_ready_apk_is_served_from_local_storage_without_any_github_request(): void
    {
        $this->installVersion('9.9.9');
        $this->setAndroidVersion('customer', '9.8.7');
        $payload = 'verified-local-customer-apk';
        $artifact = $this->readyArtifact('customer', 'Customer', $payload);

        Http::fake();

        $this->get('/downloads/apps/customer/latest.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('x-foodex-release-version', '9.8.7')
            ->assertHeader('x-foodex-artifact-sha256', $artifact->sha256)
            ->assertHeader('x-foodex-artifact-source', 'local-mirror')
            ->assertDownload('FOODEX-Customer-9.8.7.apk');

        Http::assertNothingSent();
    }

    public function test_missing_latest_apk_returns_preparing_response_and_schedules_background_mirror(): void
    {
        Queue::fake();
        $this->installVersion('9.9.9');
        $this->setAndroidVersion('customer', '9.8.7');

        $this->get('/downloads/apps/customer/latest.apk')
            ->assertStatus(503);

        Queue::assertPushed(
            MirrorMobileReleaseArtifacts::class,
            fn (MirrorMobileReleaseArtifacts $job): bool => $job->version === '9.8.7',
        );

        $this->assertDatabaseHas('mobile_release_artifacts', [
            'app' => 'customer',
            'version' => '9.8.7',
            'status' => 'pending',
        ]);
    }

    public function test_admin_status_endpoint_reports_progress_without_dispatching_work(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();
        $this->installVersion('9.8.7');

        $mirror = app(MobileReleaseArtifactMirror::class);
        $mirror->artifactsForVersion('9.8.7');

        MobileReleaseArtifact::query()
            ->where('app', 'customer')
            ->where('version', '9.8.7')
            ->update([
                'status' => 'downloading',
                'expected_bytes' => 1000,
                'downloaded_bytes' => 420,
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.mobile-apps.status'))
            ->assertOk()
            ->assertJsonPath('version', '9.8.7')
            ->assertJsonPath('artifacts.0.app', 'customer')
            ->assertJsonPath('artifacts.0.status', 'downloading')
            ->assertJsonPath('artifacts.0.progress_percent', 42)
            ->assertJsonPath('artifacts.0.download_url', null);

        Queue::assertNothingPushed();
    }

    public function test_admin_prepare_endpoint_queues_unfinished_release(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();
        $this->installVersion('9.8.7');

        $this->actingAs($admin)
            ->postJson(route('admin.mobile-apps.prepare'))
            ->assertStatus(202)
            ->assertJsonPath('version', '9.8.7');

        Queue::assertPushed(
            MirrorMobileReleaseArtifacts::class,
            fn (MirrorMobileReleaseArtifacts $job): bool => $job->version === '9.8.7',
        );
    }

    public function test_admin_retry_resets_failed_release_and_queues_background_mirror(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();
        $this->installVersion('9.8.7');

        $mirror = app(MobileReleaseArtifactMirror::class);
        $mirror->artifactsForVersion('9.8.7');

        MobileReleaseArtifact::query()
            ->where('version', '9.8.7')
            ->update([
                'status' => 'failed',
                'last_error' => 'temporary GitHub outage',
                'downloaded_bytes' => 123,
            ]);

        $this->actingAs($admin)
            ->postJson(route('admin.mobile-apps.retry'))
            ->assertStatus(202)
            ->assertJsonPath('version', '9.8.7');

        $this->assertDatabaseMissing('mobile_release_artifacts', [
            'version' => '9.8.7',
            'status' => 'failed',
        ]);

        Queue::assertPushed(
            MirrorMobileReleaseArtifacts::class,
            fn (MirrorMobileReleaseArtifacts $job): bool => $job->version === '9.8.7',
        );
    }

    public function test_dashboard_update_bootstrap_uses_mobile_policy_version_instead_of_dashboard_version(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();
        $this->installVersion('9.9.9');
        $this->setAndroidVersion('customer', '9.8.7');
        $this->setAndroidVersion('driver', '9.8.7');
        $this->setAndroidVersion('van', '9.8.7');

        $this->actingAs($admin)
            ->withSession(['status' => 'Update 9.9.9 completed successfully.'])
            ->get(route('admin.system-update.index'))
            ->assertOk();

        Queue::assertPushed(
            MirrorMobileReleaseArtifacts::class,
            fn (MirrorMobileReleaseArtifacts $job): bool => $job->version === '9.8.7',
        );
        Queue::assertNotPushed(
            MirrorMobileReleaseArtifacts::class,
            fn (MirrorMobileReleaseArtifacts $job): bool => $job->version === '9.9.9',
        );
    }

    public function test_latest_endpoint_uses_android_policy_for_customer_driver_and_van(): void
    {
        $this->installVersion('9.9.9');

        foreach ([
            'customer' => 'Customer',
            'driver' => 'Driver',
            'van' => 'Van',
        ] as $app => $label) {
            $this->setAndroidVersion($app, '9.8.7');
            $this->readyArtifact($app, $label, $app.'-apk-bytes');

            $this->get('/downloads/apps/'.$app.'/latest.apk')
                ->assertOk()
                ->assertHeader('x-foodex-release-version', '9.8.7')
                ->assertDownload('FOODEX-'.$label.'-9.8.7.apk');
        }
    }

    public function test_explicit_versioned_download_stays_independent_from_latest_policy(): void
    {
        $this->installVersion('9.9.9');
        $this->setAndroidVersion('customer', '9.8.8');
        $this->readyArtifact('customer', 'Customer', 'versioned-customer-apk');

        $this->get('/downloads/apps/customer/9.8.7.apk')
            ->assertOk()
            ->assertHeader('x-foodex-release-version', '9.8.7')
            ->assertDownload('FOODEX-Customer-9.8.7.apk');
    }

    private function installVersion(string $version): void
    {
        SystemVersion::query()->create([
            'version' => $version,
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);
    }

    private function setAndroidVersion(string $app, string $version): void
    {
        AppVersion::query()->updateOrCreate(
            ['app' => $app, 'platform' => 'android'],
            [
                'latest_version' => $version,
                'minimum_supported_version' => $version,
                'force_update' => false,
                'store_url' => 'https://foodex.50sols.com/downloads/apps/'.$app.'/latest.apk',
            ],
        );
    }

    private function readyArtifact(string $app, string $label, string $payload): MobileReleaseArtifact
    {
        $directory = storage_path('app/private/mobile-releases/9.8.7');
        File::ensureDirectoryExists($directory, 0750);
        $filename = 'FOODEX-'.$label.'-9.8.7.apk';
        $relativePath = 'mobile-releases/9.8.7/'.$filename;
        file_put_contents(storage_path('app/private/'.$relativePath), $payload);

        return MobileReleaseArtifact::query()->create([
            'app' => $app,
            'version' => '9.8.7',
            'filename' => $filename,
            'status' => 'ready',
            'expected_bytes' => strlen($payload),
            'downloaded_bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'source_commit' => str_repeat('b', 40),
            'local_path' => $relativePath,
            'attempts' => 1,
            'started_at' => now(),
            'ready_at' => now(),
        ]);
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
