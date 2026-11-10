<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P133: business role archetypes may describe broader organizational
 * ownership, but assignments grant only the canonical global/store scope.
 * A role template, Sidebar projection or locale is never a new access grant.
 */
final class V2RoleArchetypeScopeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archetype_metadata_distinguishes_business_ownership_from_effective_assignment_scope(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $expected = [
            'B2B_ADMIN' => ['global', 'tenant'],
            'WAREHOUSE_MANAGER' => ['global', 'tenant'],
            'RETAIL_INVENTORY' => ['store', 'store'],
            'SECURITY_ADMINISTRATOR' => ['global', 'platform'],
        ];

        foreach ($expected as $code => [$scope, $businessScope]) {
            $definition = $catalog->definition($code);
            $this->assertIsArray($definition, $code);
            $this->assertSame($scope, $definition['scope'], $code);
            $this->assertSame($businessScope, $definition['business_scope'], $code);

            $role = Role::query()->where('code', $code)->firstOrFail();
            $this->assertSame($scope, $role->scope, $code);
            $this->assertTrue($role->is_system, $code);
            $this->assertTrue($role->is_active, $code);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $presentation = $catalog->presentation($code);
                $this->assertNotSame('', trim((string) $presentation['name']), $code);
                $this->assertNotSame('', trim((string) $presentation['scope_label']), $code);
                $this->assertSame($scope, $presentation['scope'], $code);
                $this->assertTrue($presentation['built_in'], $code);
            }
        }

        $this->assertContains(
            'inventory_wms',
            $catalog->definition('WAREHOUSE_MANAGER')['required_modules'],
        );
    }

    public function test_retail_store_role_does_not_grant_other_store_wholesale_or_template_management_access(): void
    {
        $storeA = $this->retailStore('V2-2856-A');
        $storeB = $this->retailStore('V2-2856-B');
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $storeA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertTrue($actor->hasRole('RETAIL_INVENTORY', $storeA));
            $this->assertFalse($actor->hasRole('RETAIL_INVENTORY', $storeB));
            $this->assertFalse($actor->hasRole('RETAIL_INVENTORY'));
            $this->assertTrue($actor->hasPermission('inventory.view', $storeA));
            $this->assertFalse($actor->hasPermission('inventory.view', $storeB));
            $this->assertFalse($actor->hasPermission('inventory.view'));
            $this->assertFalse($actor->hasPermission('roles.manage'));
            $this->assertFalse($actor->hasPermission('users.delete'));

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $storeA,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $otherStore = $this->actingAs($actor)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $storeB,
                ]));
            $this->assertContains($otherStore->getStatusCode(), [403, 404]);

            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory']))
                ->assertForbidden();
        }
    }

    public function test_wholesale_global_role_cannot_grant_retail_scope_or_survive_role_deactivation(): void
    {
        $retailStore = $this->retailStore('V2-2856-RETAIL');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);

        $this->assertTrue($actor->hasRole('B2B_ADMIN'));
        $this->assertTrue($actor->hasPermission('inventory.view'));
        $this->assertFalse($actor->hasPermission('inventory.view', $retailStore));
        $this->assertFalse($actor->hasPermission('users.delete'));

        $role->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasRole('B2B_ADMIN'));
        $this->assertFalse($actor->hasPermission('inventory.view'));
        $this->assertFalse($actor->hasPermission('inventory.view', $retailStore));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'inventory']));
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }
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
