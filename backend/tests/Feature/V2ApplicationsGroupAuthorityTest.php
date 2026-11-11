<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P318: The Applications sidebar group must reflect real preview and
 * platform-release permissions, never grant a runtime route of its own.
 */
final class V2ApplicationsGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_actor_has_no_applications_group_or_admin_download_access(): void
    {
        Queue::fake();
        Http::fake();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $this->assertNull($this->applicationsFor($actor));
            $this->actingAs($actor)
                ->get(route('admin.app-preview.index', [], false))
                ->assertForbidden();

            foreach ($this->downloads() as $routeName) {
                $this->actingAs($actor)
                    ->get(route($routeName, [], false))
                    ->assertForbidden();
            }
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_platform_admin_sees_one_applications_group_with_only_real_authorized_routes(): void
    {
        $actor = $this->superAdmin();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $applications = $groups->where('key', 'applications')->values();
            $this->assertCount(1, $applications);

            $group = $applications->first();
            $this->assertSame('admin.nav_groups.applications', $group['label']);

            $children = collect($group['children']);
            $this->assertSame([
                'app_preview',
                'mobile_customer_download',
                'mobile_driver_download',
                'mobile_van_download',
            ], $children->pluck('key')->values()->all());

            $this->assertSame([
                'admin.app-preview.index',
                'admin.mobile-apps.customer.download',
                'admin.mobile-apps.driver.download',
                'admin.mobile-apps.van.download',
            ], $children->pluck('route')->values()->all());

            $this->assertNull($children->firstWhere('key', 'app_preview')['permission']);
            foreach ($this->downloads() as $routeName) {
                $this->assertSame(
                    'platform.manage',
                    $children->firstWhere('route', $routeName)['permission'],
                );
            }

            foreach ($children as $child) {
                $this->assertTrue(Route::has($child['route']), $child['route']);
            }
        }
    }

    public function test_retail_store_admin_has_scoped_preview_but_no_platform_mobile_download_links(): void
    {
        Queue::fake();
        Http::fake();
        $ownStore = $this->retailStore('P318-OWN', 'P318 Own Store');
        $otherStore = $this->retailStore('P318-OTHER', 'P318 Other Store');
        $actor = $this->retailAdmin($ownStore);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $group = $this->applicationsFor($actor->fresh());
            $this->assertNotNull($group);
            $this->assertSame(['app_preview'], collect($group['children'])->pluck('key')->all());

            $preview = $group['children'][0];
            $this->assertSame('admin.app-preview.index', $preview['route']);
            $this->assertNull($preview['permission']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.app-preview.index', [], false))
                ->assertOk()
                ->assertViewIs('admin.app-preview')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('wholesaleAvailable', false)
                ->assertViewHas('retailAvailable', true)
                ->assertViewHas('retailStores', static function ($stores) use ($ownStore, $otherStore): bool {
                    return $stores->count() === 1
                        && (int) $stores->first()->getKey() === $ownStore
                        && ! $stores->contains('id', $otherStore);
                });

            foreach ($this->downloads() as $routeName) {
                $this->actingAs($actor->fresh())
                    ->get(route($routeName, [], false))
                    ->assertForbidden();
            }
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_multi_role_retail_user_does_not_inherit_platform_download_authority(): void
    {
        $store = $this->retailStore('P318-MULTI', 'P318 Multi-role Store');
        $actor = $this->retailAdmin($store);
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        $group = $this->applicationsFor($actor->fresh());
        $this->assertNotNull($group);
        $this->assertSame(['app_preview'], collect($group['children'])->pluck('key')->all());

        foreach ($this->downloads() as $routeName) {
            $this->actingAs($actor->fresh())
                ->get(route($routeName, [], false))
                ->assertForbidden();
        }
    }

    public function test_explicit_store_preview_deny_removes_group_and_blocks_direct_preview(): void
    {
        $store = $this->retailStore('P318-DENY', 'P318 Denied Store');
        $actor = $this->retailAdmin($store);
        $permission = Permission::query()->where('code', 'app_preview.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertNull($this->applicationsFor($actor->fresh()));

        $this->actingAs($actor->fresh())
            ->get(route('admin.app-preview.index', [], false))
            ->assertForbidden();
    }

    public function test_inactive_account_has_no_applications_navigation_even_if_role_is_privileged(): void
    {
        $actor = $this->superAdmin();
        $actor->forceFill(['is_active' => false])->save();

        $this->assertNull($this->applicationsFor($actor->fresh()));
    }

    /** @return array<string, mixed>|null */
    private function applicationsFor(User $user): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'applications');
    }

    /** @return list<string> */
    private function downloads(): array
    {
        return [
            'admin.mobile-apps.customer.download',
            'admin.mobile-apps.driver.download',
            'admin.mobile-apps.van.download',
        ];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailStore(string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
