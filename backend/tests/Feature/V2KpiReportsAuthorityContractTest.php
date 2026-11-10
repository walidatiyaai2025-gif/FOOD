<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2KpiReportsAuthorityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_kpis_are_discoverable_and_scoped_to_assigned_store_in_arabic_and_english(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $foreignStore = $this->store('B2C', 'V2-KPI-FOREIGN');
        $myStore = $this->store('B2C', 'V2-KPI-OWN');
        $this->order($foreignStore, 'V2-KPI-FOREIGN-ORDER', 80);
        $this->order($myStore, 'V2-KPI-OWN-ORDER', 25);

        $user = $this->retailAdmin($myStore);

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'analytics');
        $this->assertIsArray($items);
        $keys = collect($items['children'])->pluck('key');
        $this->assertTrue($keys->contains('b2c_reports'));
        $this->assertFalse($keys->contains('reports_center'));

        $this->assertSame(
            [$myStore],
            app(OperationalTenantScope::class)->allowedStoreIds($user, 'reports.view', 'B2C'),
        );
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($user, 'reports.view', 'B2B'),
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->update(['locale' => $locale]);
            $this->actingAs($user->fresh())
                ->get('/admin/b2c/reports?store_id='.$myStore)
                ->assertOk();

            $response = $this->actingAs($user->fresh())->get(
                route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $myStore, 'channel' => 'b2c',
                ]),
            )->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee('class="kpis"', false)
                ->assertSee(__('reports.kpis.orders'));

            $response->assertViewHas('data', static function (array $data): bool {
                return ($data['kpis']['orders'] ?? null) === 1
                    && (float) ($data['kpis']['gross_value'] ?? -1) === 25.0;
            });

            $this->actingAs($user->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $foreignStore, 'channel' => 'b2c',
                ]))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $myStore, 'channel' => 'b2b',
                ]))->assertForbidden();
        }
    }

    public function test_wholesale_kpis_use_existing_reports_center_and_reject_retail_channel(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = $this->store('B2B', 'V2-KPI-WHOLESALE');

        $user = User::query()->create([
            'name' => 'Wholesale Report Operator',
            'email' => 'v2-kpi-wholesale@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $analytics = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'analytics');
        $this->assertIsArray($analytics);
        $this->assertTrue(collect($analytics['children'])->pluck('key')->contains('reports_center'));

        $this->actingAs($user)->get(route('admin.reports.index', [
            'report' => 'orders', 'store_id' => $storeId, 'channel' => 'b2b',
        ]))->assertOk()->assertSee('class="kpis"', false)
            ->assertSee('dir="ltr"', false);

        $this->actingAs($user)->get(route('admin.reports.index', [
            'report' => 'orders', 'store_id' => $storeId, 'channel' => 'b2c',
        ]))->assertForbidden();
    }

    public function test_unassigned_actor_cannot_open_kpi_report_even_with_a_valid_store_id(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = $this->store('B2C', 'V2-KPI-DENIED');
        $user = User::query()->create([
            'name' => 'No Reports Permission',
            'email' => 'v2-kpi-no-role@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($user))
            ->contains('key', 'analytics'));

        $this->actingAs($user)->get(route('admin.reports.index', [
            'report' => 'orders', 'store_id' => $storeId, 'channel' => 'b2c',
        ]))->assertForbidden();
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail KPI Manager',
            'email' => 'v2-kpi-retail@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        return $user;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, string $number, float $amount): void
    {
        $customer = Customer::query()->create([
            'name' => 'KPI Synthetic Customer '.$number,
            'type' => 'b2c',
        ]);
        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'delivered',
            'currency' => 'EGP',
            'subtotal' => $amount,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $amount,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }
}
