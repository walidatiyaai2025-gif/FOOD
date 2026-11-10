<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T356R12: the existing Administration page tree is a governance surface,
 * not an independent RBAC grant or an alternate route.
 */
final class V2PageTreePermissionsSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_single_existing_admin_link_and_page_title_are_business_named_in_both_locales(): void
    {
        $admin = $this->actor('SUPER_ADMIN');

        foreach ([
            'ar' => ['title' => 'شجرة الصفحات والصلاحيات', 'direction' => 'rtl'],
            'en' => ['title' => 'Page Tree & Permissions', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($admin);
            $items = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'sidebar_role_access')
                ->values();

            $this->assertCount(1, $items, 'Existing authorized navigation must not create a duplicate page.');
            $administration = collect($groups)->firstWhere('key', 'administration');
            $this->assertNotNull($administration);
            $this->assertContains('sidebar_role_access', array_column($administration['children'], 'key'));

            $item = $items->first();
            $this->assertSame('sidebar_admin.role_access_nav', $item['label']);
            $this->assertSame('admin.sidebar-access.roles', $item['route']);
            $this->assertSame('roles.manage', $item['permission']);
            $this->assertSame([], $item['params']);
            $this->assertSame($expected['title'], __($item['label']));
            $this->assertSame($expected['title'], __('sidebar_admin.role_access_title'));

            $this->actingAs($admin)
                ->get(route('admin.sidebar-access.roles'))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['title']);
        }
    }

    public function test_renamed_sidebar_never_grants_cross_channel_or_unprivileged_read_write_access(): void
    {
        $wholesale = $this->actor('B2B_ADMIN');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $unprivileged = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2729-RETAIL',
            'name' => 'V2 2729 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $retailStoreId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$wholesale, $retail, $unprivileged] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->assertFalse($actor->fresh()->hasPermission('roles.manage'));

                $visibleKeys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')
                    ->all();
                $this->assertNotContains('sidebar_role_access', $visibleKeys);

                $before = DB::table('admin_sidebar_role_visibility')->count();
                $this->actingAs($actor)
                    ->get(route('admin.sidebar-access.roles'))
                    ->assertForbidden();
                $this->actingAs($actor)
                    ->put(route('admin.sidebar-access.roles.update', $targetRole), [
                        'item_keys' => ['sidebar_role_access'],
                    ])
                    ->assertForbidden();
                $this->assertSame($before, DB::table('admin_sidebar_role_visibility')->count());
                $this->assertDatabaseMissing('audit_logs', [
                    'user_id' => $actor->getKey(),
                    'event' => 'admin.sidebar.role_visibility.updated',
                ]);
            }
        }
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}
