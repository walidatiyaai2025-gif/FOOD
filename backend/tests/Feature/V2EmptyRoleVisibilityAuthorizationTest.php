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
 * T015R07: an empty legacy Sidebar role-visibility table is not an RBAC
 * policy. Only the canonical permissions, route and channel guards decide.
 */
final class V2EmptyRoleVisibilityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_empty_role_visibility_keeps_authorized_catalog_access_and_denies_roleless_users(): void
    {
        $admin = $this->globalActor('B2B_ADMIN');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            $this->assertTrue($admin->fresh()->hasPermission('catalog.view'));
            $this->assertContains('catalog_management', $this->navigationKeys($admin->fresh()));
            $this->actingAs($admin->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->assertFalse($roleless->fresh()->hasPermission('catalog.view'));
            $this->assertNotContains('catalog_management', $this->navigationKeys($roleless->fresh()));
            $this->actingAs($roleless->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();

            // Viewing/navigation must not backfill a legacy visibility ACL.
            $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->count());
        }
    }

    public function test_legacy_visibility_rows_only_change_presentation_not_the_route_authorizer(): void
    {
        $admin = $this->globalActor('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permissionIds = $role->permissions()->pluck('permissions.id')->all();
        $now = now();

        DB::table('admin_sidebar_role_visibility')->insert([
            [
                'role_id' => $role->getKey(),
                'item_key' => 'catalog_management',
                'is_visible' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_id' => $role->getKey(),
                'item_key' => 'sidebar_role_access',
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->assertTrue($admin->fresh()->hasPermission('catalog.view'));
            $this->assertFalse($admin->fresh()->hasPermission('roles.manage'));
            $this->assertNotContains('catalog_management', $this->navigationKeys($admin->fresh()));
            $this->assertNotContains('sidebar_role_access', $this->navigationKeys($admin->fresh()));

            $this->actingAs($admin->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk();
            $this->actingAs($admin->fresh())
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
        }

        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $admin->getKey()]);
        $this->assertSame(2, DB::table('admin_sidebar_role_visibility')->count());
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
