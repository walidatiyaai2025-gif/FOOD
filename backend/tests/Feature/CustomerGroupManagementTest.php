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
