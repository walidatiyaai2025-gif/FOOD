<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_b2b_customer_can_view_owned_invoice_and_statement_with_audit(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('finance-buyer@example.test', 'active');
        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-58-1',
            'status' => 'issued',
            'currency' => 'KWD',
            'subtotal' => 11.500,
            'discount_total' => 1.000,
            'delivery_total' => 1.000,
            'tax_total' => 1.000,
            'total' => 12.500,
            'store_name_snapshot' => 'FOODEX Wholesale',
            'customer_name_snapshot' => 'B2B Buyer',
            'customer_email_snapshot' => 'finance-buyer@example.test',
            'customer_phone_snapshot' => '55510000',
            'payment_method_snapshot' => 'account',
            'payment_status_snapshot' => 'partial',
            'commercial_snapshot' => ['internal_quote_id' => 999],
            'issued_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'description' => 'Wholesale item',
            'sku_snapshot' => 'WHO-58',
            'quantity' => 1,
            'unit_price' => 12.500,
            'line_discount_total' => 1.000,
            'line_tax_total' => 1.000,
            'line_total' => 12.500,
            'currency' => 'KWD',
            'price_tier_code_snapshot' => 'INTERNAL-TIER',
            'line_snapshot' => json_encode(['secret_internal' => 'hidden']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payments')->insert(['invoice_id' => $invoice->id, 'provider' => 'account', 'provider_reference' => 'PAY-58', 'status' => 'paid', 'amount' => 5, 'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/invoices?status=partially_paid&q=INV-58-1')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', 'INV-58-1')
            ->assertJsonPath('data.0.display_status', 'partially_paid')
            ->assertJsonPath('data.0.paid_amount', 5)
            ->assertJsonPath('data.0.outstanding_amount', 7.5)
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonPath('summary.totals.0.currency', 'KWD')
            ->assertJsonPath('summary.totals.0.total', 12.5)
            ->assertJsonPath('summary.totals.0.paid', 5)
            ->assertJsonPath('summary.totals.0.outstanding', 7.5)
            ->assertJsonPath('meta.has_more', false);
        $detail = $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-58-1')
            ->assertJsonPath('data.subtotal', 11.5)
            ->assertJsonPath('data.discount_total', 1)
            ->assertJsonPath('data.delivery_total', 1)
            ->assertJsonPath('data.tax_total', 1)
            ->assertJsonPath('data.total', 12.5)
            ->assertJsonPath('data.paid_amount', 5)
            ->assertJsonPath('data.outstanding_amount', 7.5)
            ->assertJsonPath('data.seller.name', 'FOODEX Wholesale')
            ->assertJsonPath('data.customer.name', 'B2B Buyer')
            ->assertJsonPath('data.customer.email', 'finance-buyer@example.test')
            ->assertJsonPath('data.items.0.sku', 'WHO-58')
            ->assertJsonPath('data.items.0.description', 'Wholesale item')
            ->assertJsonPath('data.items.0.discount_total', 1)
            ->assertJsonPath('data.items.0.tax_total', 1)
            ->assertJsonPath('data.payments.0.method', 'account')
            ->assertJsonPath('data.payments.0.reference', 'PAY-58')
            ->assertJsonPath('data.payments.0.amount', 5)
            ->assertJsonPath('data.pdf_path', '/api/v1/b2b/invoices/'.$invoice->id.'/download');

        $pdf = $this->get('/api/v1/b2b/invoices/'.$invoice->id.'/download?locale=ar');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $detail->assertJsonMissingPath('data.commercial_snapshot')
            ->assertJsonMissingPath('data.items.0.line_snapshot')
            ->assertJsonMissingPath('data.items.0.price_tier_code_snapshot')
            ->assertJsonMissingPath('data.payments.0.provider_reference');
        $this->getJson('/api/v1/b2b/account-statement')->assertOk()->assertJsonPath('data.total_debits', 12.5)->assertJsonPath('data.total_credits', 5)->assertJsonPath('data.balance', -7.5);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.invoice_viewed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.statement_viewed']);
    }

    public function test_invoice_details_resolve_product_images_in_bulk_and_preserve_store_context(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('finance-images@example.test', 'active');

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'FIN-EA',
            'name' => 'Finance Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bTypeId,
            'code' => 'B2B-FIN-IMG',
            'name' => 'B2B Finance Images',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'finance-images',
            'name' => 'Finance Images Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Finance Images',
            'slug' => 'finance-images',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productIds = [];
        foreach ([
            ['sku' => 'FIN-IMG-PRIMARY', 'name' => 'Primary image product'],
            ['sku' => 'FIN-IMG-FALLBACK', 'name' => 'Fallback image product'],
            ['sku' => 'FIN-IMG-NONE', 'name' => 'No image product'],
        ] as $product) {
            $productIds[] = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalogId,
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'sku' => $product['sku'],
                'name' => $product['name'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_images')->insert([
            [
                'product_id' => $productIds[0],
                'path' => '/images/not-primary.svg',
                'sort_order' => 0,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $productIds[0],
                'path' => 'https://cdn.example.test/primary.svg',
                'sort_order' => 9,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $productIds[1],
                'path' => '/images/later.svg',
                'sort_order' => 7,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $productIds[1],
                'path' => '/images/fallback.svg',
                'sort_order' => 2,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'store_id' => $storeId,
            'invoice_number' => 'INV-IMG-2303',
            'status' => 'issued',
            'currency' => 'KWD',
            'subtotal' => 6,
            'total' => 6,
            'store_name_snapshot' => 'B2B Finance Images',
            'customer_name_snapshot' => 'B2B Buyer',
            'customer_email_snapshot' => 'finance-images@example.test',
            'issued_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $invoice->id,
                'product_id' => $productIds[0],
                'description' => 'Primary image item',
                'sku_snapshot' => 'FIN-IMG-PRIMARY',
                'quantity' => 1,
                'unit_price' => 1,
                'line_discount_total' => 0,
                'line_tax_total' => 0,
                'line_total' => 1,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $invoice->id,
                'product_id' => $productIds[1],
                'description' => 'Fallback image item',
                'sku_snapshot' => 'FIN-IMG-FALLBACK',
                'quantity' => 1,
                'unit_price' => 2,
                'line_discount_total' => 0,
                'line_tax_total' => 0,
                'line_total' => 2,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $invoice->id,
                'product_id' => $productIds[2],
                'description' => 'No image item',
                'sku_snapshot' => 'FIN-IMG-NONE',
                'quantity' => 1,
                'unit_price' => 3,
                'line_discount_total' => 0,
                'line_tax_total' => 0,
                'line_total' => 3,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.image_url', 'https://cdn.example.test/primary.svg')
            ->assertJsonPath('data.items.1.image_url', url('/images/fallback.svg'))
            ->assertJsonPath('data.items.2.image_url', null);

        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id.'?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeId);
    }

    public function test_invoice_ownership_and_account_approval_are_enforced(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$owner, $ownerCustomer] = $this->buyer('finance-owner@example.test', 'active');
        $invoice = Invoice::query()->create(['customer_id' => $ownerCustomer->id, 'invoice_number' => 'INV-58-2', 'status' => 'issued', 'currency' => 'KWD', 'total' => 2, 'issued_at' => now()]);
        [$other] = $this->buyer('finance-other@example.test', 'active');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)->assertNotFound();
        $this->get('/api/v1/b2b/invoices/'.$invoice->id.'/download')->assertNotFound();

        [$pending] = $this->buyer('finance-pending@example.test', 'pending');
        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/b2b/account-statement')->assertForbidden();
    }

    private function buyer(string $email, string $status): array
    {
        $user = User::query()->create(['name' => 'B2B Buyer', 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => 'B2B Buyer', 'email' => $email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'company_name' => 'Buyer Co', 'status' => $status]);

        return [$user, $customer];
    }
}
