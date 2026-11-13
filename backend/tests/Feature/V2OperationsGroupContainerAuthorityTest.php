<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P206 / #2536: the Operations business group is a navigation projection,
 * never an authority independent of the canonical Orders HTTP guard.
 */
final class V2OperationsGroupContainerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_operations_group_links_real_authorized_workspaces_in_both_locales(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $operator = $this->globalActor('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $operator->forceFill(['locale' => $locale])->save();

            $group = $this->operationsGroup($operator->fresh());
            $this->assertIsArray($group);
            $this->assertSame('admin.nav_groups.operations', $group['label']);
            $this->assertArrayNotHasKey('route', $group, 'A group header cannot authorize or open an endpoint.');

            $children = collect($group['children'])->keyBy('key');
            $this->assertTrue($children->has('order_operations'));
            $this->assertSame('admin.operations.orders.index', $children['order_operations']['route']);
            $this->assertArrayNotHasKey('b2c_orders', $children->all());
            $this->assertArrayNotHasKey('b2c_drivers', $children->all());

            foreach ($children as $child) {
                $this->assertTrue(Route::has($child['route']), 'Dead Operations link: '.$child['key']);
            }

            $this->actingAs($operator->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertNotSame(
            __('admin.nav_groups.operations', [], 'ar'),
            __('admin.nav_groups.operations', [], 'en'),
        );
    }

    public function test_retail_operations_group_is_bound_to_selected_store_and_cannot_open_wholesale_orders(): void
    {
        $ownStore = $this->retailStore('P206-OWN');
        $foreignStore = $this->retailStore('P206-FOREIGN');
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        DB::table('user_store_roles')->insert([
            'user_id' => $operator->id,
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = $this->operationsGroup($operator->fresh());
        $this->assertIsArray($group);
        $children = collect($group['children'])->keyBy('key');
        $this->assertTrue($children->has('b2c_orders'));
        $this->assertSame('admin.b2c.module', $children['b2c_orders']['route']);
        $this->assertSame(
            ['module' => 'orders', 'store_id' => (string) $ownStore],
            $children['b2c_orders']['params'],
        );
        $this->assertFalse($children->has('b2b_drivers'));

        $this->actingAs($operator->fresh())
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $ownStore], false))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->actingAs($operator->fresh())
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $foreignStore], false))
            ->assertNotFound();

        $this->actingAs($operator->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();
    }

    public function test_roleless_inactive_and_explicitly_denied_actors_cannot_gain_orders_through_group_visibility(): void
    {
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertNull($this->operationsGroup($roleless));
        $this->actingAs($roleless)
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();

        $inactive = $this->globalActor('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertNull($this->operationsGroup($inactive->fresh()));
        $this->actingAs($inactive->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();

        $agent = $this->globalActor('CUSTOMER_SERVICE_AGENT');
        $this->assertTrue($agent->hasPermission('orders.view'));
        $permissionId = Permission::query()->where('code', 'orders.view')->firstOrFail()->id;

        DB::table('user_permission_overrides')->insert([
            'user_id' => $agent->id,
            'permission_id' => $permissionId,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $agent->forceFill(['has_permission_overrides' => true])->save();

        $group = $this->operationsGroup($agent->fresh());
        $this->assertFalse(collect($group['children'] ?? [])->contains('key', 'order_operations'));
        $this->actingAs($agent->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();
    }

    /** @return array<string, mixed>|null */
    private function operationsGroup(User $actor): ?array
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'operations');
    }

    private function globalActor(string $role): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $actor->fresh();
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
