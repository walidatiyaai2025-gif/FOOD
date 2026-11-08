<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_groups_support_multiple_memberships_and_idempotent_bulk_assignment(): void
    {
        $admin = $this->admin();
        $storeId = app(WholesalePrincipal::class)->storeId();
        $first = app(B2bCustomerService::class)->create(['name' => 'Group A Customer']);
        $second = app(B2bCustomerService::class)->create(['name' => 'Group B Customer']);
        $customerIds = [(int) $first->legacy_customer_id, (int) $second->legacy_customer_id];

        foreach (['Preferred', 'Campaign A'] as $name) {
            $this->actingAs($admin)->post(route('admin.b2b.customer-groups.store'), [
                'store_id' => $storeId, 'name' => $name, 'priority' => 5,
            ])->assertRedirect();
        }

        $groups = DB::table('commercial_customer_groups')->where('store_id', $storeId)->pluck('id')->all();
        $this->assertCount(2, $groups);
        foreach ($groups as $groupId) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->actingAs($admin)->post(route('admin.b2b.customer-groups.members', ['group' => $groupId]), [
                    'store_id' => $storeId,
                    'operation' => 'add',
                    'customer_ids' => $customerIds,
                ])->assertRedirect();
            }
        }
        $this->assertSame(4, DB::table('commercial_customer_group_members')
            ->whereIn('customer_group_id', $groups)->count());

        $this->actingAs($admin)->post(route('admin.b2b.customer-groups.members', ['group' => $groups[0]]), [
            'store_id' => $storeId, 'operation' => 'remove', 'customer_ids' => [$customerIds[0]],
        ])->assertRedirect();
        $this->assertSame(3, DB::table('commercial_customer_group_members')
            ->whereIn('customer_group_id', $groups)->count());
        $this->assertDatabaseHas('commercial_customer_group_members', [
            'customer_group_id' => $groups[1], 'customer_id' => $customerIds[0],
        ]);
    }

    public function test_customer_group_mutations_reject_outside_wholesale_identity_and_other_store(): void
    {
        $admin = $this->admin();
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->actingAs($admin)->post(route('admin.b2b.customer-groups.store'), [
            'store_id' => $storeId, 'name' => 'Scoped', 'priority' => 1,
        ])->assertRedirect();
        $id = (int) DB::table('commercial_customer_groups')->where('name', 'Scoped')->value('id');

        $retailCustomerId = DB::table('customers')->insertGetId([
            'type' => 'b2c', 'name' => 'Unrelated Retail Customer',
            'phone' => '55500122', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post(route('admin.b2b.customer-groups.members', ['group' => $id]), [
            'store_id' => $storeId, 'operation' => 'add', 'customer_ids' => [$retailCustomerId],
        ])->assertSessionHasErrors('customer_ids');

        $this->actingAs($admin)->patch(route('admin.b2b.customer-groups.update', ['group' => $id]), [
            'store_id' => 999999, 'name' => 'Tampered', 'priority' => 0, 'is_active' => 1,
        ])->assertNotFound();
        $this->assertDatabaseHas('commercial_customer_groups', ['id' => $id, 'name' => 'Scoped']);
    }

    public function test_retail_groups_are_strictly_isolated_by_store_and_customer_identity(): void
    {
        $retailRoleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId, 'code' => 'GROUP-STORE-A',
            'name' => 'Group Store A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId, 'code' => 'GROUP-STORE-B',
            'name' => 'Group Store B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $retailAdmin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retailAdmin->id,
            'store_id' => $storeA,
            'role_id' => $retailRoleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyIds = [];
        foreach ([$storeA, $storeB] as $index => $storeId) {
            $legacyId = (int) DB::table('customers')->insertGetId([
                'type' => 'b2c', 'name' => 'Retail Group Customer '.($index + 1),
                'phone' => '5550080'.($index + 1),
                'email' => 'retailgroup'.($index + 1).'@example.test',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('b2c_customers')->insert([
                'legacy_customer_id' => $legacyId, 'store_id' => $storeId,
                'name' => 'Retail Group Customer '.($index + 1),
                'phone' => '5550080'.($index + 1),
                'email' => 'retailgroup'.($index + 1).'@example.test',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $legacyIds[] = $legacyId;
        }

        $this->actingAs($retailAdmin)
            ->get(route('admin.b2c.customer-groups.index', ['store_id' => $storeA]))
            ->assertOk()
            ->assertSee('Retail Group Customer 1')
            ->assertDontSee('Retail Group Customer 2');

        $this->actingAs($retailAdmin)
            ->post(route('admin.b2c.customer-groups.store'), [
                'store_id' => $storeA, 'name' => 'Retail Segments', 'priority' => 5,
            ])
            ->assertRedirect();
        $group = (int) DB::table('commercial_customer_groups')
            ->where('store_id', $storeA)->where('name', 'Retail Segments')->value('id');

        $this->actingAs($retailAdmin)
            ->post(route('admin.b2c.customer-groups.members', ['group' => $group]), [
                'store_id' => $storeA,
                'operation' => 'add', 'customer_ids' => [$legacyIds[0]],
            ])->assertRedirect();
        $this->assertDatabaseHas('commercial_customer_group_members', [
            'customer_group_id' => $group, 'customer_id' => $legacyIds[0],
        ]);

        $this->actingAs($retailAdmin)
            ->post(route('admin.b2c.customer-groups.members', ['group' => $group]), [
                'store_id' => $storeA,
                'operation' => 'add', 'customer_ids' => [$legacyIds[1]],
            ])->assertSessionHasErrors('customer_ids');

        $this->actingAs($retailAdmin)
            ->patch(route('admin.b2c.customer-groups.update', ['group' => $group]), [
                'store_id' => $storeB, 'name' => 'Tampered',
                'priority' => 0, 'is_active' => 1,
            ])->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::query()->create([
            'name' => 'Group Platform Admin', 'email' => 'groups-admin@example.test',
            'password' => 'password123', 'locale' => 'en', 'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }
}
