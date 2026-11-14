<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2CustomerInvoiceStoreContextTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $invoiceId;

    private User $customerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-INVOICE-CONTEXT-STORE',
            'name' => 'Invoice Context Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customerUser = User::query()->create([
            'name' => 'Invoice Context Customer',
            'email' => 'invoice-context-customer@example.test',
            'password' => 'test-password',
            'is_active' => true,
        ]);

        $customer = app(B2cCustomerService::class)->create($this->storeId, [
            'name' => 'Invoice Context Customer',
            'email' => $this->customerUser->email,
        ], $this->customerUser);

        $this->invoiceId = (int) DB::table('invoices')->insertGetId([
            'store_id' => $this->storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'invoice_number' => 'V2-INV-CONTEXT-1',
            'status' => 'issued',
            'channel' => 'b2c',
            'store_name_snapshot' => 'Invoice Context Retail',
            'customer_name_snapshot' => 'Invoice Context Customer',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'total' => 10,
            'revision' => 1,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->customerUser);
    }

    public function test_owned_invoice_show_and_pdf_support_valid_or_omitted_store_context(): void
    {
        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id='.$this->storeId.'&channel=b2c')
            ->assertOk()
            ->assertJsonPath('data.id', $this->invoiceId);

        $this->getJson('/api/v1/invoices/'.$this->invoiceId)
            ->assertOk()
            ->assertJsonPath('data.store_id', $this->storeId);

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id='.$this->storeId.'&channel=b2c&locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF', false);

        $this->withHeader('X-FOODEX-Store-ID', (string) $this->storeId)
            ->getJson('/api/v1/invoices/'.$this->invoiceId)
            ->assertOk();
    }

    public function test_malformed_or_overflowing_store_context_is_rejected_on_show_and_pdf(): void
    {
        foreach (['', ' ', '0', '-1', 'abc', '1.0', '1e0', '99999999999999999999999999'] as $value) {
            $context = '?store_id='.rawurlencode($value);

            $this->getJson('/api/v1/invoices/'.$this->invoiceId.$context)
                ->assertNotFound();

            $this->get('/api/v1/invoices/'.$this->invoiceId.'/download'.$context)
                ->assertNotFound();
        }

        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id%5B%5D='.$this->storeId)
            ->assertNotFound();

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id%5B%5D='.$this->storeId)
            ->assertNotFound();

        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id='.($this->storeId + 1))
            ->assertNotFound();

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id='.($this->storeId + 1))
            ->assertNotFound();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_invalid_query_context_cannot_shadow_a_valid_store_header(): void
    {
        $this->withHeader('X-FOODEX-Store-ID', (string) $this->storeId)
            ->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id=invalid')
            ->assertNotFound();

        // ConvertEmptyStringsToNull must not make a present query key disappear.
        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id=')
            ->assertNotFound();

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id=')
            ->assertNotFound();

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id=invalid')
            ->assertNotFound();
    }

    public function test_foreign_customer_and_wrong_channel_still_cannot_open_invoice(): void
    {
        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?channel=b2b&store_id='.$this->storeId)
            ->assertNotFound();

        $foreignUser = User::query()->create([
            'name' => 'Foreign Invoice Customer',
            'email' => 'foreign-invoice-context@example.test',
            'password' => 'test-password',
            'is_active' => true,
        ]);
        Sanctum::actingAs($foreignUser);

        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?store_id='.$this->storeId)
            ->assertNotFound();

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?store_id='.$this->storeId)
            ->assertNotFound();
    }

    public function test_channel_context_accepts_owned_matching_or_omitted_context(): void
    {
        $this->getJson('/api/v1/invoices/'.$this->invoiceId)
            ->assertOk()
            ->assertJsonPath('data.id', $this->invoiceId);

        $this->getJson('/api/v1/invoices/'.$this->invoiceId.'?channel=B2C')
            ->assertOk()
            ->assertJsonPath('data.channel', 'b2c');

        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download?channel=b2c&locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withHeader('X-FOODEX-Customer-Domain', ' b2c ')
            ->getJson('/api/v1/invoices/'.$this->invoiceId)
            ->assertOk()
            ->assertJsonPath('data.id', $this->invoiceId);
    }

    public function test_malformed_supplied_channel_context_is_404_on_detail_and_pdf(): void
    {
        // A valid header must not hide an explicitly malformed query value.
        $this->withHeader('X-FOODEX-Customer-Domain', 'b2c');

        foreach (['', ' ', 'van', 'b2b', 'b2c,b2b', '0'] as $badChannel) {
            $query = '?channel='.rawurlencode($badChannel);

            $this->getJson('/api/v1/invoices/'.$this->invoiceId.$query)
                ->assertNotFound();

            $this->get('/api/v1/invoices/'.$this->invoiceId.'/download'.$query)
                ->assertNotFound();
        }

        $arrayQuery = '?channel%5B%5D=b2c';
        $this->getJson('/api/v1/invoices/'.$this->invoiceId.$arrayQuery)
            ->assertNotFound();
        $this->get('/api/v1/invoices/'.$this->invoiceId.'/download'.$arrayQuery)
            ->assertNotFound();

        $this->assertDatabaseCount('invoices', 1);
    }

}
