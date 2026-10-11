<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\CustomerReplicaReportService;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerReplicaReportsTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStoreId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $existing = DB::table('stores')->where('code', 'WHOLESALE-MAIN')->value('id');
        $this->wholesaleStoreId = $existing === null
            ? $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale')
            : (int) $existing;

        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_customer_360_exposes_three_locked_report_entries(): void
    {
        [$platform] = $this->customerFixture();
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Customer reports')
            ->assertSee('Customer account statement')
            ->assertSee('Detailed customer item movement')
            ->assertSee('Customer item movement summary')
            ->assertSee('data-customer-replica-report="account-statement"', false)
            ->assertSee('data-customer-replica-report="item-detail"', false)
            ->assertSee('data-customer-replica-report="item-summary"', false);
    }

    public function test_replica_report_service_preserves_sales_returns_and_statement_math(): void
    {
        [$platform, $b2b, $product] = $this->customerFixture();
        $this->commercialFixture($platform, $b2b->id, $product);

        /** @var CustomerReplicaReportService $reports */
        $reports = app(CustomerReplicaReportService::class);

        $detail = $reports->build($b2b, CustomerReplicaReportService::ITEM_DETAIL, '2026-10-01', '2026-10-11');
        $this->assertCount(2, $detail['rows']);
        $this->assertSame('مبيعات', $detail['rows'][0]['movement_type']);
        $this->assertSame(20.0, $detail['rows'][0]['quantity']);
        $this->assertSame(14100.0, $detail['rows'][0]['net_value']);
        $this->assertSame('مرتجعات', $detail['rows'][1]['movement_type']);
        $this->assertSame(-5.0, $detail['rows'][1]['quantity']);
        $this->assertSame(-3525.0, $detail['rows'][1]['net_value']);
        $this->assertSame(10575.0, $detail['total']);

        $summary = $reports->build($b2b, CustomerReplicaReportService::ITEM_SUMMARY, '2026-10-01', '2026-10-11');
        $this->assertCount(1, $summary['rows']);
        $this->assertSame(20.0, $summary['rows'][0]['sales']);
        $this->assertSame(5.0, $summary['rows'][0]['returns']);
        $this->assertSame(15.0, $summary['rows'][0]['net_sold']);
        $this->assertSame(10575.0, $summary['rows'][0]['net_value']);

        $statement = $reports->build($b2b, CustomerReplicaReportService::ACCOUNT_STATEMENT, '2026-10-01', '2026-10-11');
        $this->assertSame(10575.0, $statement['total_debit']);
        $this->assertSame(3000.0, $statement['total_credit']);
        $this->assertSame(7575.0, $statement['closing_debit']);
        $this->assertSame(0.0, $statement['closing_credit']);
    }

    public function test_replica_report_view_and_pdf_are_customer_scoped_and_real_pdf(): void
    {
        [$platform, $b2b, $product] = $this->customerFixture();
        $this->commercialFixture($platform, $b2b->id, $product);
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $viewer = route('admin.customer-360.reports.show', [
            'platformCustomer' => $platform->id,
            'report' => 'item-detail',
            'from' => '2026-10-01',
            'to' => '2026-10-11',
        ]);

        $this->actingAs($admin)
            ->get($viewer)
            ->assertOk()
            ->assertSee('مسحوبات عميل تفصيلي بالاصناف')
            ->assertSee($platform->name)
            ->assertSee('data-report-frame', false);

        $response = $this->actingAs($admin)->get(route('admin.customer-360.reports.pdf', [
            'platformCustomer' => $platform->id,
            'report' => 'account-statement',
            'from' => '2026-10-01',
            'to' => '2026-10-11',
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('attachment; filename="FOODEX-account-statement-customer-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_retail_admin_cannot_read_wholesale_customer_replica_reports(): void
    {
        [$platform] = $this->customerFixture();
        $retailStore = $this->store('B2C', 'REPORT-RETAIL', 'Report Retail');
        $retailAdmin = $this->retailAdmin($retailStore);

        $response = $this->actingAs($retailAdmin)->get(route('admin.customer-360.reports.show', [
            'platformCustomer' => $platform->id,
            'report' => 'account-statement',
            'from' => '2026-10-01',
            'to' => '2026-10-11',
        ]));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_replica_pdf_contract_contains_locked_legacy_labels(): void
    {
        $source = file_get_contents(app_path('Services/CustomerReplicaReportPdfService.php'));
        $this->assertIsString($source);

        foreach ([
            'كشف حساب عميل',
            'مسحوبات عميل تفصيلي بالاصناف',
            'مسحوبات عميل بالاصناف',
            'قيمة الحركة',
            'رصيد اول الفتره',
            'صافي القيمة',
            'صافي المباع',
            'Design By www.Softarkan.com',
            'Desigend by : WWW.Softarkan.Com',
            'foodex-economical-group.webp',
        ] as $lockedText) {
            $this->assertStringContainsString($lockedText, $source);
        }
    }

    /**
     * @return array{0:PlatformCustomer,1:\App\Models\B2bCustomer,2:int}
     */
    private function customerFixture(): array
    {
        $user = app(PlatformCustomerService::class)->register([
            'name' => 'ختم الجامعه',
            'email' => 'replica-customer@example.test',
            'phone' => '+201000000811',
            'password' => 'Password123!',
            'locale' => 'ar',
        ]);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $b2b = app(CustomerDomainResolver::class)->b2b($user);

        $category = (int) DB::table('categories')->insertGetId([
            'name' => 'بطاطس',
            'slug' => 'replica-potatoes',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'REP-CRT',
            'name' => 'كرتونة',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'category_id' => $category,
            'brand_id' => null,
            'unit_id' => $unit,
            'sku' => '2',
            'name' => 'بطاطس فودكس بوم فريت 12.5 كجم',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$platform, $b2b, $product];
    }

    private function commercialFixture(PlatformCustomer $platform, int $b2bCustomerId, int $productId): void
    {
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $this->wholesaleStoreId,
            'customer_id' => $platform->legacy_customer_id,
            'b2b_customer_id' => $b2bCustomerId,
            'b2c_customer_id' => null,
            'address_id' => null,
            'order_number' => '4327',
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'EGP',
            'subtotal' => 10575,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10575,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);

        $invoice = (int) DB::table('invoices')->insertGetId([
            'order_id' => $order,
            'store_id' => $this->wholesaleStoreId,
            'customer_id' => $platform->legacy_customer_id,
            'platform_customer_id' => $platform->id,
            'b2b_customer_id' => $b2bCustomerId,
            'b2c_customer_id' => null,
            'invoice_number' => '4327',
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'subtotal' => 10575,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'total' => 10575,
            'issued_at' => '2026-10-01 09:15:00',
            'revision' => 1,
            'created_at' => '2026-10-01 09:15:00',
            'updated_at' => '2026-10-01 09:15:00',
        ]);

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $invoice,
                'product_id' => $productId,
                'description' => 'بطاطس فودكس بوم فريت 12.5 كجم',
                'sku_snapshot' => '2',
                'quantity' => 20,
                'unit_price' => 705,
                'line_total' => 14100,
                'currency' => 'EGP',
                'created_at' => '2026-10-01 09:15:00',
                'updated_at' => '2026-10-01 09:15:00',
            ],
            [
                'invoice_id' => $invoice,
                'product_id' => $productId,
                'description' => 'بطاطس فودكس بوم فريت 12.5 كجم',
                'sku_snapshot' => '2',
                'quantity' => -5,
                'unit_price' => 705,
                'line_total' => -3525,
                'currency' => 'EGP',
                'created_at' => '2026-10-06 09:15:00',
                'updated_at' => '2026-10-06 09:15:00',
            ],
        ]);

        DB::table('payments')->insert([
            'order_id' => $order,
            'invoice_id' => $invoice,
            'provider' => 'account',
            'provider_reference' => '2621',
            'status' => 'paid',
            'amount' => 3000,
            'currency' => 'EGP',
            'created_at' => '2026-10-05 10:00:00',
            'updated_at' => '2026-10-05 10:00:00',
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => 'retail-reports@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalAdmin(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-reports@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
