<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\V2SidebarMetadata;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P382: Sidebar rendering and its metadata are projections of existing,
 * scoped server authorization, not a new permission or entitlement source.
 */
final class V2CanonicalSidebarAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sidebar_metadata_cannot_expand_canonical_navigation_across_roles_channels_and_locales(): void
    {
        $wholesale = $this->globalActor('B2B_ADMIN', 'wholesale');
        $retail = $this->retailActor();
        $restricted = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'p382-restricted@example.test',
        ]);

        foreach ([$wholesale, $retail, $restricted] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $navigation = app(AdminNavigation::class)->groupsFor($actor->fresh());
                $metadata = app(V2SidebarMetadata::class)->forUser($actor->fresh());

                $this->assertEqualsCanonicalizing(
                    $this->navigationKeys($navigation),
                    $this->metadataKeys($metadata),
                    'Metadata must not invent a Sidebar key for '.$locale,
                );

                foreach ($metadata as $group) {
                    foreach ($group['items'] as $item) {
                        $this->assertExistingRoutes($item);
                    }
                }
            }
        }

        $this->assertNotContains('b2c_dashboard', $this->navigationKeys(
            app(AdminNavigation::class)->groupsFor($wholesale->fresh()),
        ));
        $this->assertNotContains('b2b_dashboard', $this->navigationKeys(
            app(AdminNavigation::class)->groupsFor($retail->fresh()),
        ));
        $this->assertNotContains('sidebar_layout', $this->metadataKeys(
            app(V2SidebarMetadata::class)->forUser($restricted->fresh()),
        ));
    }

    public function test_explicit_global_deny_removes_inherited_sidebar_link_without_editing_role_permissions(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'deny');
        $permission = Permission::query()->where('code', 'catalog.view')->firstOrFail();

        $this->assertTrue($actor->hasPermission('catalog.view'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('catalog.view'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains('catalog_management', $this->navigationKeys(
                app(AdminNavigation::class)->groupsFor($actor->fresh()),
            ));
            $this->assertNotContains('catalog_management', $this->metadataKeys(
                app(V2SidebarMetadata::class)->forUser($actor->fresh()),
            ));
        }

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'permission_id' => $permission->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    public function test_sidebar_metadata_does_not_authorize_direct_http_requests_or_cross_channel_access(): void
    {
        $restricted = User::factory()->create([
            'is_active' => true,
            'locale' => 'ar',
            'email' => 'p382-http-restricted@example.test',
        ]);
        $retail = $this->retailActor();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $restricted->forceFill(['locale' => $locale])->save();
            $retail->forceFill(['locale' => $locale])->save();

            // Metadata is intentionally read-only: requesting it cannot mint
            // roles, grants, module readiness or an HTTP access capability.
            $this->assertNotContains('sidebar_layout', $this->metadataKeys(
                app(V2SidebarMetadata::class)->forUser($restricted->fresh()),
            ));
            $this->actingAs($restricted->fresh())
                ->get(route('admin.sidebar-layout.index'))
                ->assertForbidden();

            $this->assertNotContains('b2b_dashboard', $this->metadataKeys(
                app(V2SidebarMetadata::class)->forUser($retail->fresh()),
            ));
            $this->actingAs($retail->fresh())
                ->get('/admin/b2b/orders')
                ->assertForbidden();
        }
    }

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $this->appendNavigationKeys($keys, $item);
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function appendNavigationKeys(array &$keys, array $item): void
    {
        $keys[] = (string) $item['key'];
        foreach ((array) ($item['children'] ?? []) as $child) {
            $this->appendNavigationKeys($keys, $child);
        }
    }

    /** @return list<string> */
    private function metadataKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                $this->appendMetadataKeys($keys, $item);
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function appendMetadataKeys(array &$keys, array $item): void
    {
        $keys[] = (string) $item['item_key'];
        foreach ((array) ($item['children'] ?? []) as $child) {
            $this->appendMetadataKeys($keys, $child);
        }
    }

    private function assertExistingRoutes(array $item): void
    {
        $this->assertTrue(Route::has((string) $item['route']), 'Unregistered route on '.$item['item_key']);
        foreach ((array) ($item['children'] ?? []) as $child) {
            $this->assertExistingRoutes($child);
        }
    }

    private function globalActor(string $roleCode, string $suffix): User
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'p382-'.$suffix.'@example.test',
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor->fresh();
    }

    private function retailActor(): User
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'p382-retail@example.test',
        ]);
        $storeId = DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P382-RETAIL',
            'name' => 'P382 Retail Scope',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }
}
