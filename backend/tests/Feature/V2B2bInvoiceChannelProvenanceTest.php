<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2B2bInvoiceChannelProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_invoice_list_rejects_contradictory_retail_channel_and_store_provenance(): void
    {
        [$user, $legacy, $buyer] = $this->buyer('owner');
        $wholesaleStore = $this->store('B2B', 'P3512-WHOLESALE');
        $retailStore = $this->store('B2C', 'P3512-RETAIL');

        $valid = $this->invoice($legacy, $buyer, $wholesaleStore, 'b2b', 'P3512-VALID');
        $historical = $this->invoice($legacy, $buyer, null, null, 'P3512-HISTORICAL');
        $wrongChannel = $this->invoice($legacy, $buyer, $wholesaleStore, 'b2c', 'P3512-CROSS-CHANNEL');
        $wrongStore = $this->invoice($legacy, $buyer, $retailStore, 'b2b', 'P3512-CROSS-STORE');

        $retailCustomer = B2cCustomer::query()->create([
            'store_id' => $retailStore,
            'user_id' => $user->getKey(),
            'name' => 'Retail customer',
            'email' => 'retail-p3512@example.test',
        ]);
        $wrongCustomerDomain = $this->invoice(
            $legacy,
            $buyer,
            $wholesaleStore,
            'b2b',
            'P3512-DUAL-DOMAIN',
            (int) $retailCustomer->getKey(),
        );

        Sanctum::actingAs($user);

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();

            $response = $this->getJson('/api/v1/b2b/invoices?per_page=20');
            $response->assertOk()
                ->assertJsonPath('summary.invoice_count', 2)
                ->assertJsonPath('meta.total', 2);

            $ids = collect($response->json('data'))->pluck('id')->all();
            $this->assertEqualsCanonicalizing([$valid->id, $historical->id], $ids);

            foreach ([$wrongChannel, $wrongStore, $wrongCustomerDomain] as $denied) {
                $this->assertNotContains((int) $denied->getKey(), $ids);
            }
        }

        $this->assertDatabaseHas('invoices', ['id' => $wrongChannel->id, 'channel' => 'b2c']);
        $this->assertDatabaseHas('invoices', ['id' => $wrongStore->id, 'store_id' => $retailStore]);
        $this->assertDatabaseHas('invoices', ['id' => $wrongCustomerDomain->id, 'b2c_customer_id' => $retailCustomer->id]);
    }

    public function test_detail_and_pdf_authorize_the_same_invoice_provenance_as_the_list(): void
    {
        [$user, $legacy, $buyer] = $this->buyer('detail');
        $wholesaleStore = $this->store('B2B', 'P3512-WHOLESALE-PDF');
        $retailStore = $this->store('B2C', 'P3512-RETAIL-PDF');
        $valid = $this->invoice($legacy, $buyer, $wholesaleStore, 'b2b', 'P3512-PDF-OK');
        $historical = $this->invoice($legacy, $buyer, null, null, 'P3512-PDF-LEGACY');
        $wrongChannel = $this->invoice($legacy, $buyer, $wholesaleStore, 'b2c', 'P3512-PDF-CHANNEL');
        $wrongStore = $this->invoice($legacy, $buyer, $retailStore, 'b2b', 'P3512-PDF-STORE');

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/b2b/invoices/'.$valid->id)->assertOk();
        $this->getJson('/api/v1/b2b/invoices/'.$historical->id)->assertOk();

        foreach (['ar', 'en'] as $locale) {
            foreach ([$wrongChannel, $wrongStore] as $denied) {
                $this->getJson('/api/v1/b2b/invoices/'.$denied->id)
                    ->assertNotFound();
                $this->get('/api/v1/b2b/invoices/'.$denied->id.'/download?locale='.$locale)
                    ->assertNotFound();
            }
        }

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'b2b.finance.invoice_downloaded',
            'auditable_id' => $wrongChannel->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'b2b.finance.invoice_viewed',
            'auditable_id' => $wrongStore->id,
        ]);

        [$outsider] = $this->buyer('outsider');
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/b2b/invoices/'.$valid->id)->assertNotFound();
        $this->get('/api/v1/b2b/invoices/'.$valid->id.'/download')->assertNotFound();
        $this->getJson('/api/v1/b2b/invoices')->assertJsonPath('summary.invoice_count', 0);
    }

    /** @return array{0: User, 1: Customer, 2: B2bCustomer} */
    private function buyer(string $suffix): array
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Buyer '.$suffix,
            'email' => 'buyer-'.$suffix.'-p3512@example.test',
        ]);
        $domain = B2bCustomer::query()->create([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacy->id,
            'name' => 'Buyer '.$suffix,
            'email' => 'buyer-'.$suffix.'-p3512@example.test',
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacy->id,
            'b2b_customer_id' => $domain->id,
            'company_name' => 'Buyer '.$suffix,
            'status' => 'active',
        ]);

        return [$user, $legacy, $domain];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoice(
        Customer $legacy,
        B2bCustomer $customer,
        ?int $storeId,
        ?string $channel,
        string $number,
        ?int $b2cCustomerId = null,
    ): Invoice {
        return Invoice::query()->create([
            'customer_id' => $legacy->id,
            'b2b_customer_id' => $customer->id,
            'b2c_customer_id' => $b2cCustomerId,
            'store_id' => $storeId,
            'channel' => $channel,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 12.500,
            'issued_at' => now(),
            'due_at' => now()->addDay(),
        ]);
    }
}
