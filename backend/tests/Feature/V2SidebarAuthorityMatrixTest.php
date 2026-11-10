<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 P156: Sidebar is an authority projection, never an authorization source.
 * Exercises the combined permission, tenant/channel/store and ERP readiness
 * boundaries instead of introducing a second Sidebar permission policy.
 */
final class V2SidebarAuthorityMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_and_retail_navigation_reflect_separate_server_scopes_in_ar_and_en(): void
    {
        $retailStore = $this->retailStore('P156-RETAIL-OWN');
        $foreignStore = $this->retailStore('P156-RETAIL-OTHER');
        $wholesale = $this->globalActor('B2B_ADMIN', 'wholesale');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $retailRole = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'role_id' => $retailRole->getKey(),
            'store_id' => $retailStore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();
            $retail->forceFill(['locale' => $locale])->save();

            $wholesaleNavigation = app(AdminNavigation::class);
            $this->assertSame(['b2b'], array_keys($wholesaleNavigation->for($wholesale->fresh())));
            $this->assertNotNull($this->item($wholesale->fresh(), 'catalog_management'));
            $this->assertNull($this->item($wholesale->fresh(), 'b2c_inventory'));
            $this->actingAs($wholesale)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $retailNavigation = app(AdminNavigation::class);
            $this->assertSame(['b2c'], array_keys($retailNavigation->for($retail->fresh())));
            $ownInventory = $this->item($retail->fresh(), 'b2c_inventory');
            $this->assertNotNull($ownInventory);
            $this->assertSame('admin.b2c.module', $ownInventory['route']);
            $this->assertSame((string) $retailStore, $ownInventory['params']['store_id']);
            $this->assertNull($this->item($retail->fresh(), 'b2b_inventory'));

            $this->actingAs($retail)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $retailStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $foreignResponse = $this->actingAs($retail)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreignStore,
            ], false));
            $this->assertContains($foreignResponse->getStatusCode(), [403, 404]);

            $this->actingAs($retail)
                ->get(route('admin.b2b.module', ['module' => 'inventory'], false))
                ->assertForbidden();
        }
    }

    public function test_user_with_global_wholesale_and_store_retail_roles_keeps_each_channel_and_store_boundary(): void
    {
        $ownStore = $this->retailStore('P156-MIXED-OWN');
        $otherStore = $this->retailStore('P156-MIXED-FOREIGN');
        $mixed = $this->globalActor('B2B_ADMIN', 'mixed');

        DB::table('user_store_roles')->insert([
            'user_id' => $mixed->getKey(),
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->getKey(),
            'store_id' => $ownStore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $mixed->forceFill(['locale' => $locale])->save();

            $this->assertSame(
                ['b2b', 'b2c'],
                array_keys(app(AdminNavigation::class)->for($mixed->fresh())),
            );
            $this->assertNotNull($this->item($mixed->fresh(), 'catalog_management'));
            $retailItem = $this->item($mixed->fresh(), 'b2c_inventory');
            $this->assertNotNull($retailItem);
            $this->assertSame((string) $ownStore, $retailItem['params']['store_id']);

            $this->actingAs($mixed)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($mixed)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $foreignResponse = $this->actingAs($mixed)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $otherStore,
            ], false));
            $this->assertContains($foreignResponse->getStatusCode(), [403, 404]);
        }
    }

    public function test_explicit_permission_deny_blocks_forged_visible_sidebar_and_direct_url_in_both_locales(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = $this->globalActor('B2B_ADMIN', 'deny');
        $catalogPermission = Permission::query()->where('code', 'catalog.view')->firstOrFail();
        $initialRolePermissions = $role->permissions()->pluck('permissions.id')->all();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->getKey(),
            'item_key' => 'catalog_management',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => $catalogPermission->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->assertFalse($actor->fresh()->hasPermission('catalog.view'));
            $this->assertNull($this->item($actor->fresh(), 'catalog_management'));
            $this->actingAs($actor)
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->getKey(),
            'item_key' => 'catalog_management',
            'is_visible' => true,
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'permission_id' => $catalogPermission->getKey(),
            'effect' => 'deny',
        ]);
        $this->assertEqualsCanonicalizing(
            $initialRolePermissions,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'role.updated')->count());
    }

    public function test_erp_sidebar_requires_operational_certification_beyond_enabled_and_entitled_and_http_fails_closed(): void
    {
        $owner = $this->globalActor('SUPER_ADMIN', 'erp');
        $modules = app(ModuleRegistry::class);
        $version = $modules->manifest('procurement')->version;
        $manager = app(ModuleEntitlementManager::class);
        $scope = EntitlementScope::platform();

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $manager->set('procurement', $scope, true, null, $owner);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();

            // ENABLED and an entitlement do not establish operational readiness.
            config()->set('enterprise_module_readiness.certified_versions', []);
            config()->set('enterprise_module_readiness.workflow_attestations', []);
            $this->assertNull($this->item($owner->fresh(), 'erp_procurement'));
            $this->actingAs($owner)
                ->get(route('admin.erp.procurement.index', [], false))
                ->assertForbidden();

            // Exact-version readiness permits *discovery*, while the route
            // retains its own real HTTP, tenant and permission checks.
            config()->set('enterprise_module_readiness.certified_versions.procurement', $version);
            // Without reviewed workflow evidence, exact-version certification is insufficient.
            $this->assertNull($this->item($owner->fresh(), 'erp_procurement'));
            // Synthetic proof is a test-only fixture, never live certification.
            SyntheticErpWorkflowEvidence::install();
            $item = $this->item($owner->fresh(), 'erp_procurement');
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);

            $manager->set('procurement', $scope, false, null, $owner);
            $this->assertNull($this->item($owner->fresh(), 'erp_procurement'));
            $manager->set('procurement', $scope, true, null, $owner);

            // A stale certification must not present a live link, even if
            // the lifecycle and entitlement remain unchanged.
            config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
            $this->assertNull($this->item($owner->fresh(), 'erp_procurement'));
            $this->actingAs($owner)
                ->get(route('admin.erp.procurement.index', [], false))
                ->assertForbidden();
        }

        // Disabling a module is a different state from enabled-but-uncertified.
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'disable', $owner);
        $this->assertNull($this->item($owner->fresh(), 'erp_procurement'));
        $this->actingAs($owner)
            ->get(route('admin.erp.procurement.index', [], false))
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    private function globalActor(string $code, string $name): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'v2-p156-'.$name.'@example.test',
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

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

    /** @return array<string, mixed>|null */
    private function item(User $user, string $key): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === $key) {
                    return $item;
                }
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if ($child['key'] === $key) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }
}
