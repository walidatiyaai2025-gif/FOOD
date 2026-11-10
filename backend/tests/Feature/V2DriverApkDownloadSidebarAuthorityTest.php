<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class V2DriverApkDownloadSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_owner_driver_download_sidebar_resolves_to_real_authorized_pending_route_in_arabic_and_english(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->globalUser('SUPER_ADMIN', 'v2-driver-download-owner@example.test');
        SystemVersion::query()->create([
            'version' => '9.9.9',
            'installed_at' => now(),
            'package_hash' => str_repeat('c', 64),
        ]);
        AppVersion::query()->updateOrCreate(
            ['app' => 'driver', 'platform' => 'android'],
            [
                'latest_version' => '9.8.7',
                'minimum_supported_version' => '9.8.7',
                'force_update' => false,
                'store_url' => 'https://foodex.example.test/downloads/apps/driver/latest.apk',
            ],
        );

        foreach (['ar', 'en'] as $locale) {
            $owner->forceFill(['locale' => $locale])->save();

            $group = collect(app(AdminNavigation::class)->groupsFor($owner))
                ->firstWhere('key', 'applications');
            $this->assertIsArray($group);
            $item = collect($group['children'])
                ->firstWhere('key', 'mobile_driver_download');
            $this->assertIsArray($item);
            $this->assertSame('admin.mobile-apps.driver.download', $item['route']);
            $this->assertSame('platform.manage', $item['permission']);
            $this->assertSame('admin.mobile_apps.driver_download', $item['label']);
            $this->assertNotSame(
                'admin.mobile_apps.driver_download',
                trans('admin.mobile_apps.driver_download', [], $locale),
            );

            $this->actingAs($owner)
                ->get(route($item['route']))
                ->assertRedirect(route('admin.administration.index', [
                    'download_app' => 'driver',
                ]));
        }

        $this->assertNotSame(
            trans('admin.mobile_apps.driver_download', [], 'ar'),
            trans('admin.mobile_apps.driver_download', [], 'en'),
        );
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mobile_release_artifacts', [
            'app' => 'driver',
            'version' => '9.8.7',
        ]);
    }

    public function test_retail_wholesale_and_roleless_users_cannot_see_or_open_driver_apk_admin_management(): void
    {
        Queue::fake();
        Http::fake();

        $actors = [
            $this->retailStoreAdmin('v2-driver-download-retail@example.test'),
            $this->globalUser('B2B_ADMIN', 'v2-driver-download-wholesale@example.test'),
            User::query()->create([
                'name' => 'Driver APK Roleless Actor',
                'email' => 'v2-driver-download-roleless@example.test',
                'password' => 'Password1234',
                'locale' => 'en',
                'is_active' => true,
            ]),
        ];

        foreach ($actors as $actor) {
            $this->assertFalse($actor->hasPermission('platform.manage'));

            $items = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(fn (array $group) => $group['children']);
            $this->assertFalse($items->contains('key', 'mobile_driver_download'));

            $this->actingAs($actor)
                ->get(route('admin.mobile-apps.driver.download'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->getJson(route('admin.mobile-apps.status', ['app' => 'driver']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->postJson(route('admin.mobile-apps.prepare'), ['app' => 'driver'])
                ->assertForbidden();
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_inactive_super_admin_cannot_retain_driver_apk_sidebar_or_http_access(): void
    {
        Queue::fake();

        $owner = $this->globalUser('SUPER_ADMIN', 'v2-driver-download-inactive@example.test');
        $owner->forceFill(['is_active' => false])->save();

        $items = collect(app(AdminNavigation::class)->groupsFor($owner))
            ->flatMap(fn (array $group) => $group['children']);
        $this->assertFalse($items->contains('key', 'mobile_driver_download'));

        $this->actingAs($owner)
            ->get(route('admin.mobile-apps.driver.download'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Driver APK Global Actor',
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStoreAdmin(string $email): User
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-DRIVER-APK-RETAIL',
            'name' => 'Driver APK Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'name' => 'Driver APK Retail Actor',
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
