<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

final class B2bFinanceFilterExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_finance_filters_match_authoritative_b2b_store_customer_and_date_scope(): void
    {
        $principal = app(WholesalePrincipal::class)->storeId();
        $foreignStore = $this->b2bStore('FOREIGN-B2B');
        [$legacyA, $customerA] = $this->customer('Alpha Buyer', 'Alpha Company');
        [$legacyB, $customerB] = $this->customer('Beta Buyer', 'Beta Company');

        $this->invoice($principal, $legacyA, $customerA, 'INV-A-IN', '2026-10-02', 25, 10);
        $this->invoice($principal, $legacyA, $customerA, 'INV-A-OLD', '2026-09-20', 15, 0);
        $this->invoice($principal, $legacyB, $customerB, 'INV-B-IN', '2026-10-02', 40, 40);
        $this->invoice($foreignStore, $legacyA, $customerA, 'INV-FOREIGN', '2026-10-02', 999, 999);

        $admin = $this->admin('finance-filter@example.test');

        $this->actingAs($admin)
            ->get('/admin/b2b/finance?from=2026-10-01&to=2026-10-03&customer_id='.$customerA)
            ->assertOk()
            ->assertSee('INV-A-IN')
            ->assertDontSee('INV-A-OLD')
            ->assertDontSee('INV-B-IN')
            ->assertDontSee('INV-FOREIGN')
            ->assertSee('Alpha Company');
    }

    public function test_xlsx_and_pdf_exports_use_the_same_filtered_invoice_set_and_allow_empty_results(): void
    {
        $principal = app(WholesalePrincipal::class)->storeId();
        [$legacyA, $customerA] = $this->customer('Alpha Export', 'Alpha Export Company');
        [$legacyB, $customerB] = $this->customer('Beta Export', 'Beta Export Company');

        $this->invoice($principal, $legacyA, $customerA, 'INV-XLSX-KEEP', '2026-10-02', 75, 25);
        $this->invoice($principal, $legacyB, $customerB, 'INV-XLSX-DROP', '2026-10-02', 80, 80);

        $admin = $this->admin('finance-export@example.test', 'ar');
        $query = '?from=2026-10-01&to=2026-10-03&customer_id='.$customerA;

        $xlsx = $this->actingAs($admin)->get('/admin/b2b/finance/export'.$query.'&format=xlsx');
        $xlsx->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'foodex-finance-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $xlsx->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertIsString($sheet);
        $this->assertStringContainsString('INV-XLSX-KEEP', $sheet);
        $this->assertStringNotContainsString('INV-XLSX-DROP', $sheet);
        $this->assertStringContainsString('75', $sheet);
        $this->assertStringContainsString('25', $sheet);

        $pdf = $this->actingAs($admin)->get('/admin/b2b/finance/export'.$query.'&format=pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $empty = $this->actingAs($admin)
            ->get('/admin/b2b/finance/export?from=2035-01-01&to=2035-01-02&format=xlsx');
        $empty->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertNotSame('', $empty->getContent());
    }

    /** @return array{0:int,1:int} */
    private function customer(string $name, string $company): array
    {
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domain = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacy,
            'b2b_customer_id' => $domain,
            'company_name' => $company,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$legacy, $domain];
    }

    private function invoice(
        int $storeId,
        int $legacyCustomerId,
        int $customerId,
        string $number,
        string $issuedOn,
        float $total,
        float $paid,
    ): int {
        $invoice = (int) DB::table('invoices')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $customerId,
            'invoice_number' => $number,
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'KWD',
            'total' => $total,
            'issued_at' => CarbonImmutable::parse($issuedOn, 'Asia/Kuwait')->setTime(12, 0),
            'due_at' => CarbonImmutable::parse($issuedOn, 'Asia/Kuwait')->addDays(14)->setTime(12, 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($paid > 0) {
            DB::table('payments')->insert([
                'invoice_id' => $invoice,
                'provider' => 'account',
                'status' => 'paid',
                'amount' => $paid,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invoice;
    }

    private function b2bStore(string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2B')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(string $email, string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'Finance Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }
}
