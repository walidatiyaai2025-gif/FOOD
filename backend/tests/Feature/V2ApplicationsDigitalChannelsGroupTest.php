<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 P317 / actual DOCX P306: reuse authorized Applications & Digital Channels.
 * Group visibility is projected from real child HTTP permissions, never a grant.
 */
final class V2ApplicationsDigitalChannelsGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_has_one_bilingual_group_with_only_canonical_app_destinations(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');

        foreach (['ar' => 'التطبيقات والقنوات الرقمية', 'en' => 'Applications & Digital Channels'] as $locale => $title) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->where('key', 'applications')
                ->values();

            $this->assertCount(1, $groups);
            $group = $groups->first();
            $this->assertSame($title, __($group['label']));
            $this->assertSame(
                ['app_preview', 'mobile_customer_download', 'mobile_driver_download', 'mobile_van_download'],
                collect($group['children'])->pluck('key')->all(),
            );
            $this->assertSame(
                ['admin.app-preview.index', 'admin.mobile-apps.customer.download', 'admin.mobile-apps.driver.download', 'admin.mobile-apps.van.download'],
                collect($group['children'])->pluck('route')->all(),
            );

            foreach ($group['children'] as $item) {
                $this->assertTrue(Route::has($item['route']));
            }

            // Restaurant SaaS remains the authorized Stores workspace, not a
            // duplicate unscoped digital-channel destination.
            $this->assertFalse(collect($group['children'])->contains('key', 'smart_ordering'));
        }
    }

    public function test_roleless_actor_cannot_see_group_or_open_any_protected_child(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor))
            ->contains('key', 'applications'));

        $this->actingAs($actor)
            ->get(route('admin.app-preview.index'))
            ->assertForbidden();

        foreach (['customer', 'driver', 'van'] as $app) {
            $this->actingAs($actor)
                ->get(route('admin.mobile-apps.'.$app.'.download'))
                ->assertForbidden();
        }
    }

    public function test_wholesale_admin_can_preview_but_cannot_download_platform_apks(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');

        $group = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'applications');

        $this->assertIsArray($group);
        $this->assertSame(['app_preview'], collect($group['children'])->pluck('key')->all());

        $this->actingAs($actor)
            ->get(route('admin.app-preview.index'))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.mobile-apps.customer.download'))
            ->assertForbidden();
    }

    public function test_retail_preview_is_store_scoped_and_explicit_deny_removes_link_and_http_access(): void
    {
        $own = $this->retailStore('V2-P317-OWN');
        $foreign = $this->retailStore('V2-P317-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $links = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'applications')['children'] ?? [];
        $this->assertSame(['app_preview'], collect($links)->pluck('key')->all());

        $this->actingAs($actor)
            ->get(route('admin.app-preview.index', ['store_id' => $foreign->id]))
            ->assertOk()
            ->assertSee('V2-P317-OWN')
            ->assertDontSee('V2-P317-FOREIGN');

        $permissionId = Permission::query()->where('code', 'app_preview.view')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'store:'.$own->id,
            'store_id' => $own->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->contains('key', 'applications'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.app-preview.index'))
            ->assertForbidden();
    }

    private function globalUser(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
