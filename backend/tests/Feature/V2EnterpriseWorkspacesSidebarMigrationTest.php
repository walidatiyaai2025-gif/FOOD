<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T356R2: reuses the single Enterprise Workspaces route under Overview.
 * The Sidebar caption is presentation only, never a new access grant.
 */
final class V2EnterpriseWorkspacesSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_workspaces_link_is_once_under_overview_with_localized_caption_and_canonical_route(): void
    {
        $admin = $this->globalAdmin();

        foreach ([
            'ar' => ['label' => 'مساحات العمل', 'heading' => 'مساحات عمل المؤسسة', 'dir' => 'rtl'],
            'en' => ['label' => 'Workspaces', 'heading' => 'Enterprise Workspaces', 'dir' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($admin->fresh());
            $overview = collect($groups)->firstWhere('key', 'overview');
            $this->assertNotNull($overview);

            $items = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'enterprise_workspaces')
                ->values();

            $this->assertCount(1, $items, 'Enterprise Workspaces must retain one canonical Sidebar entry.');
            $this->assertContains('enterprise_workspaces', array_column($overview['children'], 'key'));

            $item = $items->first();
            $this->assertSame('enterprise_workspaces.nav_title', $item['label']);
            $this->assertSame('admin.enterprise-workspaces.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertNull($item['permission']);
            $this->assertSame($expected['label'], __($item['label']));
            $this->assertSame($expected['heading'], __('enterprise_workspaces.title'));
            $this->assertSame($expected['label'], $item['label_text'] ?? __($item['label']));

            $this->actingAs($admin)
                ->get(route('admin.enterprise-workspaces.index'))
                ->assertOk()
                ->assertSee('dir="'.$expected['dir'].'"', false)
                ->assertSee($expected['heading'])
                ->assertSee('data-foodex-mobile-web-page="enterprise-workspaces"', false)
                ->assertSee('href="'.route('admin.enterprise-workspaces.index').'"', false);
        }

        $definition = config('admin_sidebar.items.enterprise_workspaces');
        $this->assertSame('overview', $definition['group']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame([], $definition['permissions']);
    }

    public function test_workspace_caption_does_not_expose_platform_navigation_to_store_only_or_roleless_users(): void
    {
        $owned = $this->retailStore('V2-2719-OWNED');
        $foreign = $this->retailStore('V2-2719-FOREIGN');
        $retail = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $owned,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleless = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$retail, $roleless] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $visible = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')
                    ->all();
                $this->assertNotContains('enterprise_workspaces', $visible);
            }

            // Existing Retail routes remain store-scoped; a platform Sidebar rename
            // must not give a Retail actor access to another store's dashboard.
            $this->actingAs($retail)
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $owned]))
                ->assertOk();
            $this->actingAs($retail)
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();

            $this->actingAs($roleless)
                ->get(route('admin.enterprise-workspaces.index'))
                ->assertForbidden();
        }
    }

    private function globalAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
