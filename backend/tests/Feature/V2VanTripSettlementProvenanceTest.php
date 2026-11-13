<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\CollectionCustodyService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P272: distinguish real B2B Van custody from an unproven "trip settlement".
 *
 * A Van account balance must not be represented as a closed trip's amount
 * without a canonical trip-to-collection and trip-to-remittance attribution.
 */
final class V2VanTripSettlementProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_van_finance_custody_read_is_b2b_only_and_never_writes_a_settlement(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        $now = now();
        $b2bStore = $this->store('B2B', 'P272-B2B');
        $b2cStore = $this->store('B2C', 'P272-B2C');
        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'P272-VAN',
            'plate_number' => 'P272-001',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$b2bStore => '43.500', $b2cStore => '999.000'] as $storeId => $amount) {
            $accountId = (int) DB::table('collection_accounts')->insertGetId([
                'actor_type' => 'van',
                'actor_id' => $vanId,
                'store_id' => (int) $storeId,
                'currency' => 'KWD',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('custody_ledger_entries')->insert([
                'collection_account_id' => $accountId,
                'entry_type' => 'collection',
                'amount' => $amount,
                'currency' => 'KWD',
                'reference_type' => 'p272-test-collection',
                'reference_id' => $accountId,
                'created_by' => $admin->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin->fresh())
                ->get('/admin/van-finance-support?ops_tab=wallets')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $finance = $response->viewData('finance');
            $this->assertSame(1, $finance['pagination']['total']);
            $this->assertCount(1, $finance['rows']);
            $this->assertSame($b2bStore, (int) $finance['rows'][0]['store_id']);
            $this->assertEqualsWithDelta(43.5, (float) $finance['rows'][0]['custody_balance'], 0.001);
            $this->assertSame('KWD', $finance['rows'][0]['currency']);
            $this->assertDatabaseCount('custody_ledger_entries', 2);
            $this->assertDatabaseCount('remittances', 0);
        }
    }

    public function test_actor_without_finance_authority_cannot_access_van_finance_support(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)
            ->get('/admin/van-finance-support?ops_tab=reconciliation')
            ->assertForbidden();
    }

    public function test_existing_custody_schema_cannot_prove_an_individual_trip_closeout(): void
    {
        $this->assertTrue(Schema::hasColumn('van_visits', 'order_id'));
        $this->assertTrue(Schema::hasColumn('van_visits', 'store_id'));
        $this->assertFalse(Schema::hasColumn('van_visits', 'trip_id'));
        $this->assertFalse(Schema::hasColumn('collection_transactions', 'van_visit_id'));
        $this->assertFalse(Schema::hasColumn('remittances', 'trip_id'));
        $this->assertFalse(Schema::hasColumn('remittance_allocations', 'van_visit_id'));
        $this->assertFalse(Schema::hasColumn('custody_ledger_entries', 'trip_id'));
    }

    public function test_van_custody_rejects_foreign_store_or_channel_invoice_before_any_financial_write(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $homeStore = $this->store('B2B', 'P272-SECURE-B2B');
        $otherStore = $this->store('B2B', 'P272-FOREIGN-B2B');
        $retailStore = $this->store('B2C', 'P272-FOREIGN-B2C');

        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 401,
            'store_id' => $homeStore,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        $foreignInvoice = $this->invoice($otherStore, 'b2b', 'P272-FOREIGN-INVOICE');
        $corruptChannelInvoice = $this->invoice($homeStore, 'b2c', 'P272-WRONG-CHANNEL');
        $retailInvoice = $this->invoice($retailStore, 'b2c', 'P272-RETAIL-INVOICE');

        $this->assertAllocationRejected($account, $actor, $foreignInvoice, 'p272-cross-store');
        $this->assertAllocationRejected($account, $actor, $corruptChannelInvoice, 'p272-cross-channel');
        $this->assertAllocationRejected($account, $actor, $retailInvoice, 'p272-retail-from-van');

        $this->assertDatabaseCount('collection_transactions', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('custody_ledger_entries', 0);
        foreach (['p272-cross-store', 'p272-cross-channel', 'p272-retail-from-van'] as $reference) {
            $this->assertDatabaseMissing('payments', ['provider_reference' => $reference]);
        }

        $validInvoice = $this->invoice($homeStore, 'b2b', 'P272-VALID-INVOICE');
        $transaction = app(CollectionCustodyService::class)->collect(
            $account,
            $actor,
            'p272-valid-van',
            10,
            'KWD',
            'van_app',
            [['invoice_id' => (int) $validInvoice->getKey(), 'amount' => 10]],
        );

        $this->assertDatabaseHas('collection_allocations', [
            'collection_transaction_id' => $transaction->getKey(),
            'invoice_id' => $validInvoice->getKey(),
            'amount' => 10,
            'currency' => 'KWD',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $transaction->payment_id,
            'invoice_id' => $validInvoice->getKey(),
            'provider_reference' => 'p272-valid-van',
        ]);
        $this->assertDatabaseCount('custody_ledger_entries', 1);
    }

    public function test_van_cannot_allocate_to_retail_or_unknown_store_but_driver_retail_collection_remains_valid(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $retailStore = $this->store('B2C', 'P272-B2C-ROLE-BOUNDARY');
        $retailInvoice = $this->invoice($retailStore, 'b2c', 'P272-RETAIL-ROLE');

        $vanAccount = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 402,
            'store_id' => $retailStore,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $this->assertAllocationRejected($vanAccount, $actor, $retailInvoice, 'p272-b2c-van');

        $unknownScope = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 403,
            'store_id' => null,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $this->assertAllocationRejected($unknownScope, $actor, $retailInvoice, 'p272-unknown-van');

        $driverAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 404,
            'store_id' => $retailStore,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $transaction = app(CollectionCustodyService::class)->collect(
            $driverAccount,
            $actor,
            'p272-valid-b2c-driver',
            10,
            'KWD',
            'driver_app',
            [['invoice_id' => (int) $retailInvoice->getKey(), 'amount' => 10]],
        );

        $this->assertDatabaseHas('collection_allocations', [
            'collection_transaction_id' => $transaction->getKey(),
            'invoice_id' => $retailInvoice->getKey(),
            'currency' => 'KWD',
        ]);
        $this->assertDatabaseCount('custody_ledger_entries', 1);
    }

    private function assertAllocationRejected(
        CollectionAccount $account,
        User $actor,
        Invoice $invoice,
        string $idempotencyKey,
    ): void {
        try {
            app(CollectionCustodyService::class)->collect(
                $account,
                $actor,
                $idempotencyKey,
                10,
                'KWD',
                'van_app',
                [['invoice_id' => (int) $invoice->getKey(), 'amount' => 10]],
            );
            $this->fail('An out-of-scope invoice allocation must never post.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }
    }

    private function invoice(int $storeId, string $channel, string $number): Invoice
    {
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::query()->create([
            'user_id' => $user->getKey(),
            'type' => $channel,
            'name' => 'P272 Synthetic Customer',
            'email' => $user->email,
        ]);

        return Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->getKey(),
            'invoice_number' => $number,
            'status' => 'issued',
            'channel' => $channel,
            'currency' => 'KWD',
            'total' => 10,
            'issued_at' => now(),
        ]);
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
}
