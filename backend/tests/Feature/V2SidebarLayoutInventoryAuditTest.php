<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarLayoutInventoryAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_inventory_reports_real_counts_without_changing_grants_or_settings(): void
    {
        $admin = $this->admin('en');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $now = now();

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'marketing',
            'sort_order' => 80,
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'catalog_management',
            'group_key' => 'catalog',
            'sort_order' => 10,
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $registry = app(AdminSidebarManager::class)->registry();
        $auditRowsBefore = DB::table('audit_logs')->count();
        $permissionBefore = $admin->hasPermission('roles.manage');

        $response = $this->actingAs($admin)
            ->get(route('admin.sidebar-layout.index', [], false));

        $response->assertOk()
            ->assertSee('data-sidebar-inventory-audit', false)
            ->assertSee('Sidebar source-to-current inventory')
            ->assertSee('13 groups, 61 items, 25 recorded disabled entries')
            ->assertSee('dir="ltr"', false);

        $stats = $response->viewData('sidebarAudit');
        $this->assertIsArray($stats);
        $this->assertSame(count($registry['groups']), $stats['groups']);
        $this->assertSame(count($registry['items']), $stats['items']);
        $this->assertSame(1, $stats['disabled_groups']);
        $this->assertSame(1, $stats['disabled_items']);
        $this->assertSame(1, $stats['hidden_role_records']);
        // One item may have both a global disable and a saved per-role hide.
        $this->assertSame(3, $stats['disabled_records']);

        $this->assertDatabaseHas('admin_sidebar_groups', ['group_key' => 'marketing', 'is_enabled' => false]);
        $this->assertDatabaseHas('admin_sidebar_items', ['item_key' => 'catalog_management', 'is_enabled' => false]);
        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
        ]);
        $this->assertSame($auditRowsBefore, DB::table('audit_logs')->count());
        $this->assertSame($permissionBefore, $admin->fresh()->hasPermission('roles.manage'));
    }

    public function test_protected_layout_flags_do_not_become_disabled_and_arabic_is_rtl(): void
    {
        $admin = $this->admin('ar');
        $now = now();

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'administration',
            'sort_order' => 130,
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'sidebar_layout',
            'group_key' => 'administration',
            'sort_order' => 20,
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.sidebar-layout.index', [], false));

        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مطابقة جرد السايدبار مع المصدر');

        $stats = $response->viewData('sidebarAudit');
        $this->assertIsArray($stats);
        $this->assertSame(0, $stats['disabled_groups']);
        $this->assertSame(0, $stats['disabled_items']);
        $this->assertSame(0, $stats['hidden_role_records']);
        $this->assertSame(0, $stats['disabled_records']);

        // Historical saved rows are preserved despite protected effective flags.
        $this->assertDatabaseHas('admin_sidebar_groups', ['group_key' => 'administration', 'is_enabled' => false]);
        $this->assertDatabaseHas('admin_sidebar_items', ['item_key' => 'sidebar_layout', 'is_enabled' => false]);
    }

    public function test_roleless_actor_cannot_read_inventory_or_update_layout(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.sidebar-layout.index', [], false))->assertForbidden();
        $this->actingAs($user)->put(route('admin.sidebar-layout.update', [], false), [])->assertForbidden();
    }

    private function admin(string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
