<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P268: the existing Van Visits page must be discoverable only when both
 * canonical global read permissions authorize the same HTTP route.
 */
final class V2VanVisitsAuthorizedSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_visits_read_only_route_matches_sidebar_in_arabic_and_english(): void
    {
        $actor = $this->actor('P268_VISITS_READER', ['drivers.b2b.view', 'customers.view']);

        $this->assertTrue(Route::has('admin.field-operations.visits'));
        $this->assertTrue($actor->hasPermission('drivers.b2b.view'));
        $this->assertTrue($actor->hasPermission('customers.view'));
        $this->assertFalse($actor->hasPermission('drivers.b2b.manage'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $item = $this->visitsItem($actor->fresh());
            $this->assertNotNull($item, 'An authorized visit must be discoverable in '.$locale);
            $this->assertSame('admin.field-operations.visits', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertSame(
                __('admin.field_operations.visits', [], $locale),
                __($item['label'], [], $locale),
            );

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('field_operations.no_visits', [], $locale));
        }

        // Reading visit records does not imply permission to plan or transition them.
        $this->actingAs($actor->fresh())
            ->post(route('admin.field-operations.visits.store', [], false), [])
            ->assertForbidden();
    }

    public function test_both_global_permissions_are_required_for_navigation_and_direct_http(): void
    {
        foreach ([
            ['P268_DRIVER_ONLY', ['drivers.b2b.view']],
            ['P268_CUSTOMER_ONLY', ['customers.view']],
            ['P268_NO_GRANT', []],
        ] as [$code, $permissions]) {
            $actor = $this->actor($code, $permissions);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $this->assertNull($this->visitsItem($actor->fresh()));
                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.visits', [], false))
                    ->assertForbidden();
            }
        }
    }

    public function test_multi_role_allow_and_explicit_deny_resolve_through_actual_http_authority(): void
    {
        $actor = $this->actor('P268_MULTI_DRIVER', ['drivers.b2b.view']);
        $customerRole = $this->role('P268_MULTI_CUSTOMER', ['customers.view']);
        $actor->roles()->attach($customerRole->id);
        $actor = $actor->fresh();

        $this->assertNotNull($this->visitsItem($actor));
        $this->actingAs($actor)
            ->get(route('admin.field-operations.visits', [], false))
            ->assertOk();

        $permission = Permission::query()->where('code', 'customers.view')->firstOrFail();
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

        $this->assertFalse($actor->fresh()->hasPermission('customers.view'));
        $this->assertNull($this->visitsItem($actor->fresh()));
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.visits', [], false))
            ->assertForbidden();
    }

    public function test_store_scoped_retail_role_cannot_enter_global_van_visits_or_b2b_operations(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P268-RETAIL-OWN',
            'name' => 'P268 Retail Own',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $retailStoreId,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($actor->fresh()->hasPermission('drivers.b2b.view'));
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNull($this->visitsItem($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.visits', [], false))
                ->assertForbidden();
        }
    }

    public function test_roleless_and_inactive_actors_are_not_visit_operators(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->actor('P268_INACTIVE', ['drivers.b2b.view', 'customers.view']);
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertNull($this->visitsItem($roleless));

        // Inactive accounts are rejected by the mandatory Dashboard HTTP gate.
        // Calling the navigation projector outside a real session is not an
        // activation test and must not be mistaken for a usable Sidebar link.
        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.field-operations.visits', [], false))
                ->assertForbidden();
        }
    }

    /** @param list<string> $permissionCodes */
    private function actor(string $roleCode, array $permissionCodes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = $this->role($roleCode, $permissionCodes);
        $actor->roles()->attach($role->id);

        return $actor->fresh();
    }

    /** @param list<string> $permissionCodes */
    private function role(string $code, array $permissionCodes): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => 'P268 Synthetic Visit Permission Boundary',
            'description' => 'Test-only global permissions; not a production role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissionCodes)->pluck('id')->all(),
        );

        return $role;
    }

    /** @return array<string, mixed>|null */
    private function visitsItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'field_ops_visits') {
                    return $item;
                }
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if ($child['key'] === 'field_ops_visits') {
                        return $child;
                    }
                }
            }
        }

        return null;
    }
}
