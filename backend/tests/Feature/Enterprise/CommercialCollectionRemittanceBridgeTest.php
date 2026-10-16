<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialCollectionRemittanceBridge;
use App\Models\CollectionAccount;
use App\Models\Remittance;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CommercialCollectionRemittanceBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_submit_is_retry_safe_and_returns_provider_neutral_treasury_handoff(): void
    {
        [$actor, $store, $account, $orderId, $invoiceId] = $this->fixture('COMM-REMIT');
        app(CollectionCustodyService::class)->collect(
            $account,
            $actor,
            'commercial-remit-custody',
            100,
            'KWD',
            'commercial_test',
        );

        $bridge = app(CommercialCollectionRemittanceBridge::class);
        $first = $bridge->submit(
            $actor,
            $store->id,
            $account->id,
            $orderId,
            $invoiceId,
            40,
            'KWD',
            'COMM-REMIT-001',
        );
        $second = $bridge->submit(
            $actor,
            $store->id,
            $account->id,
            $orderId,
            $invoiceId,
            40,
            'KWD',
            'COMM-REMIT-001',
        );

        $this->assertSame($first['idempotency_key'], $second['idempotency_key']);
        $this->assertSame($first['remittance']['id'], $second['remittance']['id']);
        $this->assertSame('pending', $first['remittance']['status']);
        $this->assertSame('treasury.receipt.intent', $first['treasury_handoff']['kind']);
        $this->assertTrue($first['treasury_handoff']['optional']);
        $this->assertTrue($first['treasury_handoff']['provider_neutral']);
        $this->assertSame('foodex.commercial.collection.changed', $first['event']->name);
        $this->assertSame(1, $first['event']->version);
        $this->assertSame($store->id, $first['event']->storeId);
        $this->assertSame('b2b', $first['event']->channel);
        $this->assertSame('remittance', $first['event']->aggregateType);
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_retry_reference_cannot_be_reused_with_different_amount(): void
    {
        [$actor, $store, $account, $orderId, $invoiceId] = $this->fixture('COMM-RETRY');
        app(CollectionCustodyService::class)->collect(
            $account,
            $actor,
            'commercial-retry-custody',
            100,
            'KWD',
            'commercial_test',
        );

        $bridge = app(CommercialCollectionRemittanceBridge::class);
        $bridge->submit(
            $actor,
            $store->id,
            $account->id,
            $orderId,
            $invoiceId,
            20,
            'KWD',
            'COMM-RETRY-001',
        );

        try {
            $bridge->submit(
                $actor,
                $store->id,
                $account->id,
                $orderId,
                $invoiceId,
                21,
                'KWD',
                'COMM-RETRY-001',
            );
            $this->fail('Expected a mismatched idempotent retry to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_downstream_rejection_is_observable_without_corrupting_source_records(): void
    {
        [$actor, $store, $account, $orderId, $invoiceId] = $this->fixture('COMM-REJECT');
        $custody = app(CollectionCustodyService::class);
        $custody->collect(
            $account,
            $actor,
            'commercial-reject-custody',
            100,
            'KWD',
            'commercial_test',
        );

        $bridge = app(CommercialCollectionRemittanceBridge::class);
        $submitted = $bridge->submit(
            $actor,
            $store->id,
            $account->id,
            $orderId,
            $invoiceId,
            30,
            'KWD',
            'COMM-REJECT-001',
        );

        $custody->rejectRemittance(
            Remittance::query()->findOrFail($submitted['remittance']['id']),
            $actor,
        );

        $status = $bridge->status(
            $actor,
            $store->id,
            $account->id,
            $submitted['remittance']['id'],
        );

        $this->assertSame('rejected', $status['status']);
        $this->assertTrue($status['rejected']);
        $this->assertFalse($status['reconciled']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'issued']);
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_cross_store_account_and_mismatched_invoice_fail_closed(): void
    {
        [$actor, $store, $account, $orderId, $invoiceId] = $this->fixture('COMM-SCOPE');
        app(CollectionCustodyService::class)->collect(
            $account,
            $actor,
            'commercial-scope-custody',
            100,
            'KWD',
            'commercial_test',
        );

        $foreignStore = Store::query()->create([
            'store_type_id' => $store->store_type_id,
            'code' => 'COMM-FOREIGN-'.uniqid(),
            'name' => 'Foreign Wholesale Store',
            'is_active' => true,
        ]);
        $foreignAccount = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 999991,
            'store_id' => $foreignStore->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        $bridge = app(CommercialCollectionRemittanceBridge::class);

        try {
            $bridge->submit(
                $actor,
                $store->id,
                $foreignAccount->id,
                $orderId,
                $invoiceId,
                10,
                'KWD',
                'COMM-FOREIGN-001',
            );
            $this->fail('Expected a foreign-store collection account to be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $otherOrderId = $this->order($store, 'COMM-SCOPE-OTHER');
        $otherInvoiceId = $this->invoice($otherOrderId, 'COMM-SCOPE-OTHER');
        $this->expectException(ModelNotFoundException::class);

        $bridge->submit(
            $actor,
            $store->id,
            $account->id,
            $orderId,
            $otherInvoiceId,
            10,
            'KWD',
            'COMM-MISMATCH-001',
        );
    }

    /** @return array{User,Store,CollectionAccount,int,int} */
    private function fixture(string $code): array
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $actor = User::factory()->create([
            'email' => strtolower($code).'@example.test',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role->id);

        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => $actor->id,
            'store_id' => $store->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $orderId = $this->order($store, $code);
        $invoiceId = $this->invoice($orderId, $code);

        return [$actor, $store, $account, $orderId, $invoiceId];
    }

    private function order(Store $store, string $code): int
    {
        $user = User::factory()->create(['is_active' => true]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Commercial Collection Customer',
            'phone' => '+965'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'order_number' => $code.'-ORDER',
            'channel' => 'b2b',
            'status' => 'confirmed',
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoice(int $orderId, string $code): int
    {
        $customerId = (int) DB::table('orders')->where('id', $orderId)->value('customer_id');

        return (int) DB::table('invoices')->insertGetId([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'invoice_number' => $code.'-INV',
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 100,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
