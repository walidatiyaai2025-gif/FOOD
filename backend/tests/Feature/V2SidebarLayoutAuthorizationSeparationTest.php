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

/**
 * P050: Sidebar layout/visibility are presentation-only. Only the canonical
 * server-side permission, channel and tenant guards authorize Dashboard URLs.
 */
final class V2SidebarLayoutAuthorizationSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_hiding_and_moving_a_permitted_link_never_revokes_the_underlying_route(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permissionIds = $role->permissions()->pluck('permissions.id')->all();
        $manager = app(AdminSidebarManager::class);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertTrue($actor->fresh()->hasPermission('catalog.view'));
            $this->assertContains('catalog_management', $this->navigationKeys($actor->fresh()));

            $manager->saveLayout([], [
                'catalog_management' => [
                    'group_key' => 'catalog',
                    'is_enabled' => false,
                    'sort_order' => 5,
                ],
            ]);

            $this->assertNotContains('catalog_management', $this->navigationKeys($actor->fresh()));
            $this->assertTrue($actor->fresh()->hasPermission('catalog.view'));
            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $manager->saveLayout([], [
                'catalog_management' => [
                    'group_key' => 'analytics',
                    'is_enabled' => true,
                    'sort_order' => 5,
                ],
            ]);

            $this->assertContains('catalog_management', $this->navigationKeys($actor->fresh()));
            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);
            $this->assertContains(
                'catalog_management',
                array_column((array) $analytics['children'], 'key'),
            );

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk();
        }

        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->getKey()]);
    }

    public function test_enabled_layout_and_role_visibility_cannot_create_roleless_or_cross_channel_access(): void
    {
        $wholesale = $this->globalActor('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $manager = app(AdminSidebarManager::class);
        $permissionIds = $role->permissions()->pluck('permissions.id')->all();

        // A cosmetic layout may enable or regroup registered destinations,
        // but neither this configuration nor a visibility preference grants RBAC.
        $manager->saveLayout([], [
            'sidebar_role_access' => ['group_key' => 'administration', 'is_enabled' => true],
            'b2c_inventory' => ['group_key' => 'catalog', 'is_enabled' => true],
            'catalog_management' => ['group_key' => 'catalog', 'is_enabled' => true],
        ]);
        $manager->saveRoleVisibility($role, [
            'sidebar_role_access',
            'b2c_inventory',
            'catalog_management',
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            $this->assertFalse($wholesale->fresh()->hasPermission('roles.manage'));
            $this->assertFalse($roleless->fresh()->hasPermission('catalog.view'));
            $this->assertNotContains('sidebar_role_access', $this->navigationKeys($wholesale->fresh()));
            $this->assertNotContains('b2c_inventory', $this->navigationKeys($wholesale->fresh()));
            $this->assertNotContains('catalog_management', $this->navigationKeys($roleless->fresh()));

            $this->actingAs($wholesale->fresh())
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
            $this->actingAs($roleless->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();

            $crossChannel = $this->actingAs($wholesale->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => 999999], false));
            $this->assertContains($crossChannel->getStatusCode(), [403, 404]);
        }

        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertSame(0, DB::table('user_permission_overrides')
            ->whereIn('user_id', [$wholesale->getKey(), $roleless->getKey()])
            ->count());
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor->fresh();
    }

    /** @return list<string> */
    private function navigationKeys(User $actor): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $this->collectKeys($keys, $item);
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function collectKeys(array &$keys, array $item): void
    {
        $keys[] = (string) $item['key'];
        foreach ((array) ($item['children'] ?? []) as $child) {
            $this->collectKeys($keys, $child);
        }
    }
}
