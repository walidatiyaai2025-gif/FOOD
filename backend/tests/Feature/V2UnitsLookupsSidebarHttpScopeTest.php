<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 Sidebar P223: the Units & Reference Values destination must be usable
 * through the existing authorized Catalog navigation, not a fabricated link.
 * Actual HTTP permissions, stores and lookup mutations remain authoritative.
 */
final class V2UnitsLookupsSidebarHttpScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_units_page_is_discoverable_and_filtered_by_wholesale_or_retail_store_in_both_languages(): void
    {
        $storeA = $this->store('P223-A');
        $storeB = $this->store('P223-B');
        $wholesale = $this->globalUser('B2B_ADMIN', 'wholesale');
        $retail = $this->retailUser('RETAIL_INVENTORY', $storeA, 'retail');

        $this->unit('P223-GLOBAL', 'P223 Global Unit', 'global');
        $this->unit('P223-B2B', 'P223 Wholesale Unit', 'b2b');
        $this->unit('P223-A', 'P223 Store A Unit', 'store', $storeA);
        $this->unit('P223-B', 'P223 Store B Unit', 'store', $storeB);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();
            $retail->forceFill(['locale' => $locale])->save();

            $wholesaleItem = $this->navigationItem($wholesale->fresh(), 'lookup_management');
            $this->assertNotNull($wholesaleItem);
            $this->assertSame('admin.lookups.index', $wholesaleItem['route']);

            $this->actingAs($wholesale)
                ->get(route('admin.lookups.index', ['type' => 'units'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P223 Global Unit')
                ->assertSee('P223 Wholesale Unit')
                ->assertDontSee('P223 Store A Unit')
                ->assertDontSee('P223 Store B Unit');

            $retailItem = $this->navigationItem($retail->fresh(), 'lookup_management');
            $this->assertNotNull($retailItem);
            $this->assertSame('admin.lookups.index', $retailItem['route']);

            $this->actingAs($retail)
                ->get(route('admin.lookups.index', ['type' => 'units'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P223 Store A Unit')
                ->assertDontSee('P223 Wholesale Unit')
                ->assertDontSee('P223 Store B Unit')
                ->assertDontSee('P223 Global Unit');

            $this->actingAs($retail)->get(route('admin.lookups.index', [
                'type' => 'units',
                'store_id' => $storeB,
            ], false))->assertNotFound();
        }
    }

    public function test_readonly_retail_inventory_role_cannot_mutate_units_but_store_admin_cannot_escape_own_store(): void
    {
        $storeA = $this->store('P223-WRITE-A');
        $storeB = $this->store('P223-WRITE-B');
        $reader = $this->retailUser('RETAIL_INVENTORY', $storeA, 'reader');
        $manager = $this->retailUser('B2C_STORE_ADMIN', $storeA, 'manager');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $reader->forceFill(['locale' => $locale])->save();
            $manager->forceFill(['locale' => $locale])->save();
            $suffix = strtoupper($locale);

            $ownPayload = $this->unitPayload($storeA, 'P223-'.$suffix);

            $this->assertTrue($reader->fresh()->hasPermission('lookups.view', $storeA));
            $this->assertFalse($reader->fresh()->hasPermission('lookups.manage', $storeA));
            $this->actingAs($reader)
                ->post(route('admin.lookups.store', ['type' => 'units'], false), $ownPayload)
                ->assertForbidden();
            $this->assertDatabaseMissing('units', ['code' => 'P223-'.$suffix]);

            $this->actingAs($manager)
                ->post(route('admin.lookups.store', ['type' => 'units'], false), $ownPayload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('units', [
                'code' => 'P223-'.$suffix,
                'scope' => 'store',
                'store_id' => $storeA,
                'scope_key' => 'store:'.$storeA,
            ]);
            $this->assertDatabaseHas('audit_logs', ['event' => 'lookup.unit.created', 'user_id' => $manager->id]);

            $this->actingAs($manager)
                ->post(route('admin.lookups.store', ['type' => 'units'], false), $this->unitPayload($storeB, 'P223-FOREIGN-'.$suffix))
                ->assertNotFound();
            $this->assertDatabaseMissing('units', ['code' => 'P223-FOREIGN-'.$suffix]);

            $this->actingAs($manager)
                ->post(route('admin.lookups.store', ['type' => 'units'], false), [
                    ...$ownPayload,
                    'scope' => 'b2b',
                    'store_id' => null,
                    'code' => 'P223-B2B-FORGE-'.$suffix,
                ])
                ->assertForbidden();
            $this->assertDatabaseMissing('units', ['code' => 'P223-B2B-FORGE-'.$suffix]);
        }
    }

    public function test_forced_sidebar_visibility_cannot_override_a_real_user_deny_or_disabled_role(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = $this->globalUser('B2B_ADMIN', 'denied');
        $permission = Permission::query()->where('code', 'lookups.view')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'lookup_management',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
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
            $this->assertFalse($actor->fresh()->hasPermission('lookups.view'));
            $this->assertNull($this->navigationItem($actor->fresh(), 'lookup_management'));
            $this->actingAs($actor)
                ->get(route('admin.lookups.index', ['type' => 'units'], false))
                ->assertForbidden();
        }

        // The saved visual setting must not create an RBAC grant or audit.
        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'lookup_management',
            'is_visible' => true,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'role.updated')->count());

        $inactive = $this->globalUser('B2B_ADMIN', 'inactive');
        $role->forceFill(['is_active' => false])->save();

        $this->assertNull($this->navigationItem($inactive->fresh(), 'lookup_management'));
        $this->actingAs($inactive)
            ->get(route('admin.lookups.index', ['type' => 'units'], false))
            ->assertForbidden();
    }

    private function globalUser(string $roleCode, string $suffix): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'p223-'.$suffix.'@example.test',
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailUser(string $roleCode, int $storeId, string $suffix): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'p223-'.$suffix.'@example.test',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unit(string $code, string $name, string $scope, ?int $storeId = null): void
    {
        DB::table('units')->insert([
            'code' => $code,
            'name' => $name,
            'name_ar' => $name,
            'name_en' => $name,
            'decimal_places' => 0,
            'scope' => $scope,
            'scope_key' => $scope === 'store' ? 'store:'.$storeId : $scope,
            'store_id' => $storeId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,int|string> */
    private function unitPayload(int $storeId, string $code): array
    {
        return [
            'scope' => 'store',
            'store_id' => $storeId,
            'name_ar' => 'وحدة قياس',
            'name_en' => 'Measure Unit',
            'code' => $code,
            'decimal_places' => 0,
            'is_active' => 1,
        ];
    }

    /** @return array<string,mixed>|null */
    private function navigationItem(User $actor, string $key): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            if ($group['key'] !== 'catalog') {
                continue;
            }
            foreach ($group['children'] as $item) {
                if ($item['key'] === $key) {
                    return $item;
                }
            }
        }

        return null;
    }
}
