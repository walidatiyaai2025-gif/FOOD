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
 * P007: canonical Sidebar links follow effective role/permission and route
 * access, not a separately curated allowlist or legacy visibility table.
 */
final class V2SidebarAuthorizedRouteDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_assigning_and_revoking_an_authorized_role_updates_sidebar_and_protected_http_access(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $originalPermissionIds = $role->permissions()->pluck('permissions.id')->all();
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);

            $this->assertFalse($actor->hasPermission('catalog.view'));
            $this->assertNotContains('catalog_management', $this->navigationKeys($actor));
            $this->actingAs($actor)
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();

            // No legacy visibility setup, role permission mutation or manual
            // sidebar whitelist is necessary to discover an authorized route.
            $actor->roles()->attach($role);
            $granted = $actor->fresh();
            $this->assertTrue($granted->hasPermission('catalog.view'));
            $this->assertContains('catalog_management', $this->navigationKeys($granted));
            $this->actingAs($granted)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // Revocation must update the next canonical read and the server
            // route guard, even if the user previously visited that page.
            $granted->roles()->detach($role);
            $revoked = $granted->fresh();
            $this->assertFalse($revoked->hasPermission('catalog.view'));
            $this->assertNotContains('catalog_management', $this->navigationKeys($revoked));
            $this->actingAs($revoked)
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();

            $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->getKey()]);
            $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->count());
        }

        $this->assertEqualsCanonicalizing(
            $originalPermissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_a_wholesale_role_only_discovers_its_authorized_channel_links(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $keys = $this->navigationKeys($actor->fresh());

            $this->assertContains('catalog_management', $keys);
            $this->assertNotContains('b2c_inventory', $keys);
            $this->assertNotContains('sidebar_role_access', $keys);

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk();
            $this->actingAs($actor->fresh())
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();

            $crossChannel = $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => 999999], false));
            $this->assertContains($crossChannel->getStatusCode(), [403, 404]);
        }

        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->count());
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
