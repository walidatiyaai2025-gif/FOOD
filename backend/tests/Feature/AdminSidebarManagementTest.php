<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSidebarManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_visibility_can_hide_authorized_sidebar_page_without_revoking_rbac_permission(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'sidebar-role@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $before = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertTrue($before->contains('catalog_management'));
        $this->assertTrue($user->hasPermission('catalog.view'));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $this->flatten($group['children']))
            ->pluck('key');

        $this->assertFalse($after->contains('catalog_management'));
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
    }

    public function test_layout_can_move_rename_and_nest_authorized_items(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'sidebar-layout@example.test');

        DB::table('admin_sidebar_items')->insert([
            [
                'item_key' => 'catalog_management',
                'group_key' => 'analytics',
                'parent_key' => null,
                'label_ar' => 'الكتالوج المخصص',
                'label_en' => 'Custom catalog',
                'sort_order' => 1,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'item_key' => 'reports_center',
                'group_key' => 'analytics',
                'parent_key' => 'catalog_management',
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => 2,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app()->setLocale('en');
        $analytics = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'analytics');

        $this->assertNotNull($analytics);
        $catalog = collect($analytics['children'])->firstWhere('key', 'catalog_management');
        $this->assertNotNull($catalog);
        $this->assertSame('Custom catalog', $catalog['label_text']);
        $this->assertTrue(collect($catalog['children'])->contains('key', 'reports_center'));
    }

    public function test_super_admin_can_open_both_sidebar_management_pages(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'sidebar-owner@example.test');

        $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', [], false))
            ->assertOk()
            ->assertSee('sidebar_role_access', false);

        $this->actingAs($admin)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertOk()
            ->assertSee('sidebar_layout', false);
    }

    /** @param array<int,array<string,mixed>> $items
     *  @return array<int,array<string,mixed>>
     */
    private function flatten(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            $flat[] = $item;
            if (($item['children'] ?? []) !== []) {
                array_push($flat, ...$this->flatten($item['children']));
            }
        }

        return $flat;
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
