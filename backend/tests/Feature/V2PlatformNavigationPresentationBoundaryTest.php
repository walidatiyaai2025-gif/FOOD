<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 T028R03: legacy appearance preferences must not suppress the three
 * authorized platform workspaces or become a substitute for server-side RBAC.
 */
final class V2PlatformNavigationPresentationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStore;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        $this->wholesaleStore = $this->store('B2B', 'V2-T028R03-WHOLESALE');
    }

    public function test_authorized_platform_workspaces_survive_disabled_legacy_layout_groups_and_role_preferences(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = $this->actor('v2-t028r03-platform@example.test');
        $actor->roles()->attach($role->getKey());
        $actor = $actor->fresh();

        $targets = [
            'enterprise_action_center' => ['overview', 'admin.action-center.index'],
            'enterprise_workspaces' => ['overview', 'admin.enterprise-workspaces.index'],
            'treasury_master' => ['finance', 'admin.treasury.master.index'],
        ];

        foreach (array_keys($targets) as $key) {
            $this->assertContains($key, $this->visibleKeys($actor));
        }

        foreach (['overview', 'finance'] as $group) {
            DB::table('admin_sidebar_groups')->insert([
                'group_key' => $group,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($targets as $key => [$group]) {
            DB::table('admin_sidebar_items')->insert([
                'item_key' => $key,
                'group_key' => $group,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $role->getKey(),
                'item_key' => $key,
                'is_visible' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertTrue($actor->hasPermission('finance.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $items = $this->visibleItems($actor);

            foreach ($targets as $key => [, $routeName]) {
                $this->assertSame(1, $items->where('key', $key)->count(), "Missing or duplicated authorized [{$key}]");
                $this->assertSame($routeName, $items->firstWhere('key', $key)['route']);
                $this->actingAs($actor)
                    ->get(route($routeName, [], false))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }

        $matrix = app(AdminSidebarManager::class)->roleMatrix($role);
        $roleItems = collect($matrix['groups'])->flatMap(fn (array $group) => $group['items']);
        foreach (array_keys($targets) as $key) {
            $this->assertTrue($roleItems->firstWhere('key', $key)['visible_for_role']);
            $this->assertDatabaseHas('admin_sidebar_role_visibility', [
                'role_id' => $role->getKey(),
                'item_key' => $key,
                'is_visible' => false,
            ]);
            $this->assertDatabaseHas('admin_sidebar_items', [
                'item_key' => $key,
                'is_enabled' => false,
            ]);
        }
    }

    public function test_retail_store_role_preserves_scoped_workspaces_without_platform_or_cross_store_treasury_grants(): void
    {
        $storeId = $this->store('B2C', 'V2-T028R03-RETAIL');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $actor = $this->actor('v2-t028r03-retail@example.test');

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['enterprise_action_center', 'enterprise_workspaces', 'treasury_master'] as $key) {
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $role->getKey(),
                'item_key' => $key,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $keys = $this->visibleKeys($actor);
            $this->assertContains('enterprise_action_center', $keys);
            $this->assertContains('enterprise_workspaces', $keys);
            $this->assertContains('treasury_master', $keys, 'Own-store finance.view authorizes Treasury Master Data, not platform ERP Treasury.');

            $this->actingAs($actor)
                ->get(route('admin.action-center.index', [], false))
                ->assertOk();
            $workspace = $this->actingAs($actor)
                ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'sales'], false))
                ->assertOk()
                ->viewData('workspace');
            $this->assertSame('sales', $workspace['key']);
            $this->assertNotContains('system_health', array_column($workspace['cards'], 'key'));

            $this->actingAs($actor)->get(route('admin.treasury.master.index', [
                'store_id' => $storeId,
            ], false))->assertOk();

            $foreign = $this->actingAs($actor)->get(route('admin.treasury.master.index', [
                'store_id' => $this->wholesaleStore,
            ], false));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);
        }
    }

    public function test_role_visibility_edit_keeps_essential_links_protected_and_roleless_http_denied(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        app(AdminSidebarManager::class)->saveRoleVisibility($role, []);

        foreach (['enterprise_action_center', 'enterprise_workspaces', 'treasury_master'] as $key) {
            $this->assertDatabaseHas('admin_sidebar_role_visibility', [
                'role_id' => $role->getKey(),
                'item_key' => $key,
                'is_visible' => true,
            ]);
        }

        $roleless = $this->actor('v2-t028r03-roleless@example.test');
        $this->assertNotContains('enterprise_action_center', $this->visibleKeys($roleless));
        $this->assertNotContains('enterprise_workspaces', $this->visibleKeys($roleless));
        $this->assertNotContains('treasury_master', $this->visibleKeys($roleless));

        foreach (['admin.action-center.index', 'admin.enterprise-workspaces.index', 'admin.treasury.master.index'] as $routeName) {
            $this->actingAs($roleless)
                ->get(route($routeName, [], false))
                ->assertForbidden();
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function visibleItems(User $actor): Collection
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(fn (array $group) => $group['children'])
            ->flatMap(fn (array $item) => [$item, ...($item['children'] ?? [])])
            ->values();
    }

    /** @return list<string> */
    private function visibleKeys(User $actor): array
    {
        return $this->visibleItems($actor)->pluck('key')->all();
    }

    private function actor(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => 'en',
        ]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
