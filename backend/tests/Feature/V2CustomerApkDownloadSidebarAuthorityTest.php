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

final class V2CustomerApkDownloadSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_owner_can_discover_customer_apk_download_and_get_safe_pending_fallback_in_both_locales(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->globalUser('SUPER_ADMIN', 'v2-customer-apk-owner@example.test');
        SystemVersion::query()->create([
            'version' => '9.9.9',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);
        AppVersion::query()->updateOrCreate(
            ['app' => 'customer', 'platform' => 'android'],
            [
                'latest_version' => '9.8.7',
                'minimum_supported_version' => '9.8.7',
                'force_update' => false,
                'store_url' => 'https://foodex.example.test/downloads/apps/customer/latest.apk',
            ],
        );

        foreach (['ar', 'en'] as $locale) {
            $owner->forceFill(['locale' => $locale])->save();

            $applications = collect(app(AdminNavigation::class)->groupsFor($owner))
                ->firstWhere('key', 'applications');
            $this->assertIsArray($applications);

            $download = collect($applications['children'])
                ->firstWhere('key', 'mobile_customer_download');
            $this->assertIsArray($download);
            $this->assertSame('admin.mobile-apps.customer.download', $download['route']);
            $this->assertSame('platform.manage', $download['permission']);
            $this->assertSame('admin.mobile_apps.customer_download', $download['label']);
            $this->assertNotSame(
                'admin.mobile_apps.customer_download',
                trans('admin.mobile_apps.customer_download', [], $locale),
            );

            $this->actingAs($owner)
                ->get(route($download['route']))
                ->assertRedirect(route('admin.administration.index', [
                    'download_app' => 'customer',
                ]));
        }

        $this->assertNotSame(
            trans('admin.mobile_apps.customer_download', [], 'ar'),
            trans('admin.mobile_apps.customer_download', [], 'en'),
        );
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mobile_release_artifacts', [
            'app' => 'customer',
            'version' => '9.8.7',
        ]);
    }

    public function test_retail_store_and_wholesale_operators_cannot_discover_or_open_platform_customer_apk_administration(): void
    {
        Queue::fake();
        Http::fake();

        $retail = $this->retailStoreAdmin('v2-customer-apk-retail@example.test');
        $wholesale = $this->globalUser('B2B_ADMIN', 'v2-customer-apk-wholesale@example.test');
        $roleless = User::query()->create([
            'name' => 'Roleless Customer APK Actor',
            'email' => 'v2-customer-apk-roleless@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);

        foreach ([$retail, $wholesale, $roleless] as $actor) {
            $this->assertFalse($actor->hasPermission('platform.manage'));

            $items = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(fn (array $group) => $group['children']);
            $this->assertFalse($items->contains('key', 'mobile_customer_download'));

            $this->actingAs($actor)
                ->get(route('admin.mobile-apps.customer.download'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->getJson(route('admin.mobile-apps.status', ['app' => 'customer']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->postJson(route('admin.mobile-apps.prepare'), ['app' => 'customer'])
                ->assertForbidden();
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_inactive_super_admin_loses_sidebar_discoverability_and_direct_download_access(): void
    {
        Queue::fake();

        $owner = $this->globalUser('SUPER_ADMIN', 'v2-customer-apk-inactive@example.test');
        $owner->forceFill(['is_active' => false])->save();

        $items = collect(app(AdminNavigation::class)->groupsFor($owner))
            ->flatMap(fn (array $group) => $group['children']);
        $this->assertFalse($items->contains('key', 'mobile_customer_download'));

        $this->actingAs($owner)
            ->get(route('admin.mobile-apps.customer.download'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Customer APK Global Actor',
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
            'code' => 'V2-CUSTOMER-APK-RETAIL',
            'name' => 'Customer APK Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'name' => 'Customer APK Retail Actor',
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
