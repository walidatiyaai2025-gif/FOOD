<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportExportService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagementReportingSuiteTest extends TestCase
{
    use RefreshDatabase;

    private int $storeA;

    private int $storeB;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->seedReportingFixture();
    }

    public function test_orders_report_calculates_kpis_and_combined_filters(): void
    {
        Sanctum::actingAs($this->userWithRole('SUPER_ADMIN'));

        $response = $this->getJson(
            '/api/v1/admin/reports/orders?store_id='.$this->storeA.'&channel=b2c&status=delivered&from=2026-09-01&to=2026-09-30',
        )->assertOk();

        $response
            ->assertJsonPath('data.kpis.orders', 2)
            ->assertJsonPath('data.kpis.gross_value', 40)
            ->assertJsonPath('data.kpis.recognized_revenue', 40)
            ->assertJsonPath('data.kpis.average_order_value', 20)
            ->assertJsonCount(2, 'data.rows');
    }

    public function test_product_report_ranks_best_least_and_zero_sales(): void
    {
        Sanctum::actingAs($this->userWithRole('SUPER_ADMIN'));

        $response = $this->getJson(
            '/api/v1/admin/reports/products?store_id='.$this->storeA.'&from=2026-09-01&to=2026-09-30',
        )->assertOk();

        $response
            ->assertJsonPath('data.best_by_quantity.sku', 'RPT-A')
            ->assertJsonPath('data.least_by_quantity.sku', 'RPT-B')
            ->assertJsonPath('data.best_by_revenue.sku', 'RPT-A')
            ->assertJsonPath('data.kpis.selling_products', 2)
            ->assertJsonPath('data.kpis.zero_sale_products', 1);
    }

    public function test_store_scoped_report_access_cannot_cross_store_boundary(): void
    {
        $admin = $this->userWithRole('B2C_STORE_ADMIN');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $this->storeA,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/orders')->assertUnprocessable();
        $this->getJson('/api/v1/admin/reports/orders?store_id='.$this->storeB)->assertForbidden();
        $this->getJson('/api/v1/admin/reports/orders?store_id='.$this->storeA)->assertOk();
    }

    public function test_admin_center_is_bilingual_and_exports_real_files_with_audit(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');

        app()->setLocale('ar');
        $this->actingAs($admin)->get('/admin/reports?report=orders&store_id='.$this->storeA)
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تقارير الإدارة');

        app()->setLocale('en');
        $this->actingAs($admin)->get('/admin/reports?report=orders&store_id='.$this->storeA)
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Management Reports');

        foreach ([
            'xlsx' => 'PK',
            'docx' => 'PK',
            'pdf' => '%PDF-1.4',
        ] as $format => $signature) {
            $response = $this->actingAs($admin)->get(
                '/admin/reports/export?report=orders&format='.$format.'&store_id='.$this->storeA,
            )->assertOk();

            $this->assertStringStartsWith($signature, $response->getContent());
        }

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'report.exported',
        ]);
    }

    public function test_formula_injection_is_neutralized(): void
    {
        $exports = app(ReportExportService::class);

        $this->assertSame("'=2+2", $exports->sanitizeSpreadsheetValue('=2+2'));
        $this->assertSame("'+SUM(A1:A2)", $exports->sanitizeSpreadsheetValue('+SUM(A1:A2)'));
        $this->assertSame('Normal', $exports->sanitizeSpreadsheetValue('Normal'));
    }

    private function seedReportingFixture(): void
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeA = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'REPORT-A',
            'name' => 'Report Store A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'REPORT-B',
            'name' => 'Report Store B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerUser = User::query()->create([
            'name' => 'Report Customer',
            'email' => 'suite-customer@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => 'Report Customer',
            'email' => $customerUser->email,
        ]);

        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'RPT-U',
            'name' => 'Unit',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $category = (int) DB::table('categories')->insertGetId([
            'name' => 'Reporting',
            'slug' => 'reporting',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productA = $this->product($unit, $category, 'RPT-A', 'Best Product');
        $productB = $this->product($unit, $category, 'RPT-B', 'Least Product');
        $productZero = $this->product($unit, $category, 'RPT-Z', '=Zero Formula Product');

        foreach ([$productA, $productB, $productZero] as $product) {
            DB::table('store_products')->insert([
                'store_id' => $this->storeA,
                'product_id' => $product,
                'price' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $first = $this->order($this->storeA, 'RPT-001', 'delivered', 30, '2026-09-10 10:00:00');
        $second = $this->order($this->storeA, 'RPT-002', 'delivered', 10, '2026-09-12 10:00:00');
        $this->order($this->storeA, 'RPT-003', 'cancelled', 99, '2026-09-14 10:00:00');
        $this->order($this->storeB, 'RPT-004', 'delivered', 50, '2026-09-12 10:00:00');

        DB::table('order_items')->insert([
            [
                'order_id' => $first,
                'product_id' => $productA,
                'sku_snapshot' => 'RPT-A',
                'name_snapshot' => 'Best Product',
                'quantity' => 3,
                'unit_price' => 10,
                'line_total' => 30,
                'created_at' => '2026-09-10 10:00:00',
                'updated_at' => '2026-09-10 10:00:00',
            ],
            [
                'order_id' => $second,
                'product_id' => $productB,
                'sku_snapshot' => 'RPT-B',
                'name_snapshot' => 'Least Product',
                'quantity' => 1,
                'unit_price' => 10,
                'line_total' => 10,
                'created_at' => '2026-09-12 10:00:00',
                'updated_at' => '2026-09-12 10:00:00',
            ],
        ]);
    }

    private function product(int $unit, int $category, string $sku, string $name): int
    {
        return (int) DB::table('products')->insertGetId([
            'category_id' => $category,
            'unit_id' => $unit,
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $store, string $number, string $status, float $total, string $date): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $this->customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => strtolower($role).'-suite@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
