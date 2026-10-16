<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialProfitabilityEventPublisher;
use App\Models\Customer;
use App\Models\EnterpriseOutboxMessage;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Remittance;
use App\Models\Store;
use App\Models\StoreType;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use LogicException;
use Tests\TestCase;

final class CommercialProfitabilityEventPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_order_snapshot_reconciles_to_canonical_commercial_records_and_retry_is_idempotent(): void
    {
        $order = $this->order('B2B', 'SNAPSHOT');

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => 501,
            'sku_snapshot' => 'SKU-501',
            'name_snapshot' => 'Product 501',
            'quantity' => 2,
            'unit_price' => 3.500,
            'line_total' => 7.000,
        ]);

        $publisher = app(CommercialProfitabilityEventPublisher::class);
        $first = $publisher->publishOrder($order);
        $second = $publisher->publishOrder($order->fresh());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('enterprise_outbox_messages', 1);

        $event = json_decode((string) $first->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(CommercialProfitabilityEventPublisher::ORDER_EVENT, $event['type']);
        $this->assertSame(1, $event['version']);
        $this->assertSame($order->store_id, $event['scope']['store_id']);
        $this->assertSame('b2b', $event['scope']['channel']);
        $this->assertSame($order->id, $event['payload']['data']['source']['order_id']);
        $this->assertSame('KWD', $event['payload']['data']['money']['currency']);
        $this->assertSame('7.000', $event['payload']['data']['money']['subtotal']);
        $this->assertSame(501, $event['payload']['data']['items'][0]['product_id']);
        $this->assertSame('2.000', $event['payload']['data']['items'][0]['quantity']);
        $this->assertSame(0, $event['payload']['data']['collection']['collection_count']);
    }

    public function test_changed_canonical_snapshot_produces_a_new_deterministic_event_identity(): void
    {
        $order = $this->order('B2C', 'CHANGE');
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => 601,
            'sku_snapshot' => 'SKU-601',
            'name_snapshot' => 'Product 601',
            'quantity' => 1,
            'unit_price' => 5,
            'line_total' => 5,
        ]);

        $publisher = app(CommercialProfitabilityEventPublisher::class);
        $first = $publisher->publishOrder($order);

        $order->forceFill(['status' => 'confirmed'])->save();

        $second = $publisher->publishOrder($order->fresh());
        $retry = $publisher->publishOrder($order->fresh());

        $this->assertNotSame($first->event_id, $second->event_id);
        $this->assertSame($second->event_id, $retry->event_id);
        $this->assertDatabaseCount('enterprise_outbox_messages', 2);
    }

    public function test_collection_attribution_is_versioned_retry_safe_and_references_only_canonical_ids(): void
    {
        $order = $this->order('B2B', 'COLLECTION');

        $invoice = new Invoice([
            'order_id' => $order->id,
            'currency' => 'KWD',
        ]);
        $invoice->setAttribute('id', 701);

        $remittance = new Remittance([
            'collection_account_id' => 801,
            'amount' => 4.250,
            'currency' => 'KWD',
            'status' => 'pending',
        ]);
        $remittance->setAttribute('id', 901);

        $publisher = app(CommercialProfitabilityEventPublisher::class);
        $first = $publisher->publishCollection($order, $invoice, $remittance);
        $second = $publisher->publishCollection($order, $invoice, $remittance);

        $this->assertSame($first->event_id, $second->event_id);

        $event = json_decode((string) $first->payload, true, flags: JSON_THROW_ON_ERROR);
        $data = $event['payload']['data'];

        $this->assertSame(CommercialProfitabilityEventPublisher::COLLECTION_EVENT, $event['type']);
        $this->assertSame($order->id, $data['source']['order_id']);
        $this->assertSame(701, $data['source']['invoice_id']);
        $this->assertSame(901, $data['source']['remittance_id']);
        $this->assertSame(801, $data['source']['collection_account_id']);
        $this->assertSame('KWD', $data['collection']['currency']);
        $this->assertArrayNotHasKey('reference', $data['collection']);
    }

    public function test_unsupported_profitability_event_version_fails_closed_without_outbox_write(): void
    {
        $order = $this->order('B2B', 'VERSION');
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => 1001,
            'sku_snapshot' => 'SKU-1001',
            'name_snapshot' => 'Product 1001',
            'quantity' => 1,
            'unit_price' => 1,
            'line_total' => 1,
        ]);

        Config::set(
            'enterprise_events.contracts.'.CommercialProfitabilityEventPublisher::ORDER_EVENT,
            [],
        );

        try {
            app(CommercialProfitabilityEventPublisher::class)->publishOrder($order);
            $this->fail('Expected unsupported profitability event contract to fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Unsupported domain event contract', $exception->getMessage());
        }

        $this->assertSame(0, EnterpriseOutboxMessage::query()->count());
    }

    private function order(string $channel, string $suffix): Order
    {
        $storeType = StoreType::query()->where('code', strtoupper($channel))->firstOrFail();
        $store = Store::query()->create([
            'store_type_id' => $storeType->id,
            'code' => 'PROFIT-'.$suffix,
            'name' => 'Profitability '.$suffix,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Customer '.$suffix,
            'type' => strtolower($channel),
        ]);

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'PROFIT-'.$suffix,
            'channel' => strtolower($channel),
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 7,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 7,
            'quote_id' => 'quote-'.$suffix,
            'pricing_snapshot' => [
                'source' => 'canonical_quote',
                'commercial_origin' => strtolower($channel),
            ],
        ]);
    }
}
