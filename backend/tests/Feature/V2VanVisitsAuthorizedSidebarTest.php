<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_van_visits_only_disclose_wholesale_records_inside_assigned_store_scope(): void
    {
        $actor = $this->actor('P268_WHOLESALE_SCOPE', ['drivers.b2b.view', 'customers.view']);
        $ownStore = $this->scopeStore('P268-WHOLESALE-OWN', 'B2B');
        $otherStore = $this->scopeStore('P268-WHOLESALE-OTHER', 'B2B');
        $retailStore = $this->scopeStore('P268-RETAIL-PRIVATE', 'B2C');
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore,
            'role_id' => $actor->roles()->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholesaleCustomer = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'P268-OWN-WHOLESALE-CUSTOMER',
            'phone' => '9000011111',
            'email' => 'p268-own-wholesale@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailCustomer = (int) DB::table('b2c_customers')->insertGetId([
            'store_id' => $retailStore,
            'name' => 'P268-RETAIL-PRIVATE-CUSTOMER',
            'email' => 'p268-private-retail@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignCustomer = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'P268-FOREIGN-WHOLESALE-CUSTOMER',
            'phone' => '9000022222',
            'email' => 'p268-foreign-wholesale@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordVisit($actor, 'b2b', $wholesaleCustomer, $ownStore);
        $this->recordVisit($actor, 'b2b', $foreignCustomer, $otherStore);
        $this->recordVisit($actor, 'b2c', $retailCustomer, $retailStore);

        // The current user's B2B store is visible; B2C and other wholesale stores are not.
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.visits'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P268-OWN-WHOLESALE-CUSTOMER')
                ->assertDontSee('P268-RETAIL-PRIVATE-CUSTOMER')
                ->assertDontSee('P268-FOREIGN-WHOLESALE-CUSTOMER')
                ->assertDontSee('P268-RETAIL-PRIVATE')
                ->assertDontSee('P268-WHOLESALE-OTHER');
        }
    }

    public function test_van_visit_creation_and_transition_reject_retail_channel_and_foreign_store(): void
    {
        $actor = $this->actor('P268_VAN_MANAGER', ['drivers.b2b.view', 'drivers.b2b.manage', 'customers.view']);
        $ownStore = $this->scopeStore('P268-TRANSITION-OWN', 'B2B');
        $otherStore = $this->scopeStore('P268-TRANSITION-FOREIGN', 'B2B');
        $retailStore = $this->scopeStore('P268-TRANSITION-RETAIL', 'B2C');
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore,
            'role_id' => $actor->roles()->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retailVisit = $this->recordVisit($actor, 'b2c', 98765, $retailStore);
        $foreignVisit = $this->recordVisit($actor, 'b2b', 98765, $otherStore);
        $unscopedVisit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 98765,
            'store_id' => null,
            'status' => 'planned',
        ]);
        $ownVisit = $this->recordVisit($actor, 'b2b', 98765, $ownStore);
        $initialCount = VanVisit::query()->count();

        // Per-store managers cannot bind an arbitrary global B2B customer
        // without a verified wholesale customer-to-store ownership relation.
        $this->actingAs($actor)->post(route('admin.field-operations.visits.store'), [
            'customer_type' => 'b2b',
            'customer_id' => 98765,
            'store_id' => $ownStore,
        ])->assertForbidden();

        $platformAdmin = User::factory()->create(['is_active' => true]);
        $platformAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);
        $this->actingAs($platformAdmin)->post(route('admin.field-operations.visits.store'), [
            'customer_type' => 'b2c',
            'customer_id' => 98765,
        ])->assertSessionHasErrors('customer_type');
        $this->assertSame($initialCount, VanVisit::query()->count());

        $this->actingAs($actor)
            ->post(route('admin.field-operations.visits.transition', $retailVisit), ['status' => 'started'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('admin.field-operations.visits.transition', $foreignVisit), ['status' => 'started'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('admin.field-operations.visits.transition', $unscopedVisit), ['status' => 'started'])
            ->assertNotFound();
        $this->assertDatabaseHas('van_visits', ['id' => $unscopedVisit->id, 'status' => 'planned']);
        $this->assertDatabaseHas('van_visits', ['id' => $retailVisit->id, 'status' => 'planned']);
        $this->assertDatabaseHas('van_visits', ['id' => $foreignVisit->id, 'status' => 'planned']);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.visits.transition', $ownVisit), ['status' => 'started'])
            ->assertRedirect();
        $this->assertDatabaseHas('van_visits', ['id' => $ownVisit->id, 'status' => 'started']);
    }

    public function test_platform_wholesale_operator_can_create_b2b_visit_but_never_b2c_visit(): void
    {
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $operator->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);
        $storeId = $this->scopeStore('P268-PLATFORM-CREATE-B2B', 'B2B');
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'P268-PLATFORM-CREATE-CUSTOMER',
            'phone' => '9000033333',
            'email' => 'p268-platform-create@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'P268-PLATFORM-VAN',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignmentId = (int) DB::table('van_assignments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'van_id' => $vanId,
            'representative_user_id' => $operator->id,
            'status' => 'active',
            'effective_from' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)->post(route('admin.field-operations.visits.store'), [
            'assignment_id' => $assignmentId,
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'store_id' => $storeId,
        ])->assertRedirect();
        $this->assertDatabaseHas('van_visits', [
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'actor_user_id' => $operator->id,
            'status' => 'planned',
        ]);

        $before = VanVisit::query()->count();
        $this->actingAs($operator)->post(route('admin.field-operations.visits.store'), [
            'assignment_id' => $assignmentId,
            'customer_type' => 'b2c',
            'customer_id' => $customerId,
            'store_id' => $storeId,
        ])->assertSessionHasErrors('customer_type');
        $this->assertSame($before, VanVisit::query()->count());
    }

    private function scopeStore(string $code, string $type): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? StoreBusinessProfile::RETAIL_STORE->value : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordVisit(User $actor, string $channel, int $customerId, int $storeId): VanVisit
    {
        return VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => $channel,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'status' => 'planned',
            'planned_at' => now(),
        ]);
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
