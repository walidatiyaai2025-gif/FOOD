<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarLegacySettingsPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_partial_layout_migration_preserves_untouched_legacy_rows_and_original_creation_dates(): void
    {
        $sidebar = app(AdminSidebarManager::class);
        $originalDate = now()->subDays(14)->startOfSecond()->toDateTimeString();

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'analytics',
            'label_ar' => 'تقارير سابقة',
            'label_en' => 'Legacy reports',
            'icon' => '📊',
            'sort_order' => 72,
            'is_enabled' => false,
            'created_at' => $originalDate,
            'updated_at' => $originalDate,
        ]);
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'catalog_management',
            'group_key' => 'analytics',
            'parent_key' => null,
            'label_ar' => 'كتالوج سابق',
            'label_en' => 'Legacy catalog',
            'sort_order' => 91,
            'is_enabled' => false,
            'created_at' => $originalDate,
            'updated_at' => $originalDate,
        ]);

        // A partial migration must not reset omitted data, including disabled
        // choices, old translations and original audit-provenance timestamps.
        $sidebar->saveLayout([], []);
        $this->assertDatabaseHas('admin_sidebar_groups', [
            'group_key' => 'analytics',
            'label_en' => 'Legacy reports',
            'is_enabled' => false,
            'created_at' => $originalDate,
        ]);
        $this->assertDatabaseHas('admin_sidebar_items', [
            'item_key' => 'catalog_management',
            'label_en' => 'Legacy catalog',
            'is_enabled' => false,
            'created_at' => $originalDate,
        ]);

        $sidebar->saveLayout([
            'analytics' => [
                'label_ar' => 'تقارير محدثة',
                'label_en' => 'Updated reports',
                'icon' => '📊',
                'sort_order' => 72,
                'is_enabled' => false,
            ],
        ], []);

        $this->assertDatabaseHas('admin_sidebar_groups', [
            'group_key' => 'analytics',
            'label_en' => 'Updated reports',
            'is_enabled' => false,
            'created_at' => $originalDate,
        ]);
        $this->assertDatabaseHas('admin_sidebar_items', [
            'item_key' => 'catalog_management',
            'label_en' => 'Legacy catalog',
            'created_at' => $originalDate,
        ]);

        $sidebar->saveLayout([], [
            'catalog_management' => [
                'group_key' => 'analytics',
                'parent_key' => null,
                'label_ar' => 'كتالوج محدث',
                'label_en' => 'Updated catalog',
                'sort_order' => 91,
                'is_enabled' => false,
            ],
        ]);

        $this->assertDatabaseHas('admin_sidebar_items', [
            'item_key' => 'catalog_management',
            'label_en' => 'Updated catalog',
            'is_enabled' => false,
            'created_at' => $originalDate,
        ]);
    }

    public function test_role_scope_change_does_not_erase_historical_visibility_or_grant_disallowed_item(): void
    {
        $sidebar = app(AdminSidebarManager::class);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $unavailable = collect($sidebar->roleMatrix($role)['groups'])
            ->flatMap(static fn (array $group): array => $group['items'])
            ->first(static fn (array $item): bool => ! $item['base_allowed']);

        $this->assertNotNull($unavailable, 'Expected an item that is not permitted for B2B_ADMIN.');
        $itemKey = (string) $unavailable['key'];
        $originalDate = now()->subDays(14)->startOfSecond()->toDateTimeString();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => $itemKey,
            'is_visible' => true,
            'created_at' => $originalDate,
            'updated_at' => $originalDate,
        ]);

        $permissionCodes = $role->permissions()->pluck('permissions.code')->all();
        $sidebar->saveRoleVisibility($role, []);

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => $itemKey,
            'is_visible' => true,
            'created_at' => $originalDate,
        ]);
        $this->assertSame($permissionCodes, $role->fresh()->permissions()->pluck('permissions.code')->all());

        $user = User::query()->create([
            'name' => 'Historic Role Scope',
            'email' => 'v2-sidebar-legacy-role@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $keys = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();

        // The retained preference is historical data, not an RBAC grant.
        $this->assertNotContains($itemKey, $keys);
    }

    public function test_unprivileged_actors_cannot_change_legacy_layout_over_http_in_either_locale(): void
    {
        $originalDate = now()->subDays(3)->startOfSecond()->toDateTimeString();
        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'analytics',
            'label_ar' => 'تاريخ محفوظ',
            'label_en' => 'Preserved history',
            'icon' => '📊',
            'sort_order' => 30,
            'is_enabled' => true,
            'created_at' => $originalDate,
            'updated_at' => $originalDate,
        ]);
        $user = User::query()->create([
            'name' => 'Unprivileged',
            'email' => 'v2-sidebar-legacy-denied@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            $this->actingAs($user)
                ->put(route('admin.sidebar-layout.update', [], false), [
                    'groups' => ['analytics' => ['label_en' => 'Unauthorized']],
                    'items' => [],
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('admin_sidebar_groups', [
            'group_key' => 'analytics',
            'label_en' => 'Preserved history',
            'created_at' => $originalDate,
        ]);
    }
}
