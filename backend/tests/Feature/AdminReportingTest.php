<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_platform_aggregates_and_can_filter_store(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeA, $storeB] = $this->storesWithOrders();
        Sanctum::actingAs($this->userWithRole('SUPER_ADMIN'));

        $this->getJson('/api/v1/admin/reports/dashboard')->assertOk()->assertJsonPath('data.scope', 'platform')->assertJsonPath('data.orders', 2)->assertJsonPath('data.revenue', 30);
        $this->getJson('/api/v1/admin/reports/dashboard?store_id='.$storeA)->assertOk()->assertJsonPath('data.scope', 'store')->assertJsonPath('data.store_id', $storeA)->assertJsonPath('data.orders', 1)->assertJsonPath('data.revenue', 10);
        $this->assertNotSame($storeA, $storeB);
    }

    public function test_store_admin_requires_and_is_restricted_to_assigned_store(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeA, $storeB] = $this->storesWithOrders();
        $admin = $this->userWithRole('B2C_STORE_ADMIN');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert(['user_id' => $admin->id, 'store_id' => $storeA, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/dashboard')->assertUnprocessable();
        $this->getJson('/api/v1/admin/reports/dashboard?store_id='.$storeB)->assertForbidden();
        $this->getJson('/api/v1/admin/reports/dashboard?store_id='.$storeA)->assertOk()->assertJsonPath('data.orders', 1);
    }

    private function storesWithOrders(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $a = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'RPT-A', 'name' => 'A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $b = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'RPT-B', 'name' => 'B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerUser = User::query()->create(['name' => 'Report Customer', 'email' => 'customer@reports.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $customerUser->id, 'type' => 'b2c', 'name' => 'Report Customer', 'email' => $customerUser->email]);
        foreach ([[$a, 10], [$b, 20]] as [$store, $total]) {
            DB::table('orders')->insert(['store_id' => $store, 'customer_id' => $customer->id, 'order_number' => 'RPT-'.$store, 'channel' => 'b2c', 'status' => 'delivered', 'currency' => 'KWD', 'subtotal' => $total, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => $total, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$a, $b];
    }

    private function userWithRole(string $role): User
    {
        $user = User::query()->create(['name' => $role, 'email' => strtolower($role).'@reports.test', 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
