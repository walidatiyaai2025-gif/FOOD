<?php

namespace App\Enterprise\Commercial;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\EnterpriseOutboxMessage;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Remittance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class CommercialProfitabilityEventPublisher
{
    public const ORDER_EVENT = 'CommercialProfitabilityOrderSnapshot';

    public const COLLECTION_EVENT = 'CommercialProfitabilityCollectionAttribution';

    public const CONTRACT_VERSION = 1;

    private const MAX_ORDER_ITEMS = 100;

    public function __construct(
        private readonly TransactionalOutbox $outbox,
    ) {}

    public function publishOrder(Order $order): EnterpriseOutboxMessage
    {
        return DB::transaction(function () use ($order): EnterpriseOutboxMessage {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $channel = $this->channel($locked);
            $items = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->orderBy('id')
                ->limit(self::MAX_ORDER_ITEMS + 1)
                ->get([
                    'id',
                    'product_id',
                    'sku_snapshot',
                    'quantity',
                    'unit_price',
                    'line_total',
                ]);

            if ($items->count() > self::MAX_ORDER_ITEMS) {
                throw new LogicException('Commercial profitability snapshots are limited to 100 order lines.');
            }

            $collection = DB::table('collection_allocations')
                ->join('invoices', 'invoices.id', '=', 'collection_allocations.invoice_id')
                ->join(
                    'collection_transactions',
                    'collection_transactions.id',
                    '=',
                    'collection_allocations.collection_transaction_id',
                )
                ->where('invoices.order_id', $locked->getKey())
                ->where('collection_transactions.type', 'collection')
                ->selectRaw('COALESCE(SUM(collection_allocations.amount), 0) as collected_amount')
                ->selectRaw('COUNT(DISTINCT collection_transactions.id) as collection_count')
                ->first();

            $pricing = is_array($locked->pricing_snapshot) ? $locked->pricing_snapshot : [];
            $payload = [
                'contract_version' => self::CONTRACT_VERSION,
                'fact' => 'order_snapshot',
                'source' => [
                    'order_id' => (int) $locked->getKey(),
                    'order_number' => (string) $locked->order_number,
                    'status' => (string) $locked->status,
                    'store_id' => (int) $locked->store_id,
                    'warehouse_id' => $locked->warehouse_id === null ? null : (int) $locked->warehouse_id,
                    'channel' => $channel,
                ],
                'customer' => [
                    'legacy_customer_id' => (int) $locked->customer_id,
                    'b2b_customer_id' => $locked->b2b_customer_id === null ? null : (int) $locked->b2b_customer_id,
                    'b2c_customer_id' => $locked->b2c_customer_id === null ? null : (int) $locked->b2c_customer_id,
                ],
                'money' => [
                    'currency' => strtoupper((string) $locked->currency),
                    'subtotal' => (string) $locked->subtotal,
                    'discount_total' => (string) $locked->discount_total,
                    'delivery_total' => (string) $locked->delivery_total,
                    'tax_total' => (string) ($locked->tax_total ?? 0),
                    'grand_total' => (string) $locked->grand_total,
                ],
                'pricing' => [
                    'quote_id' => $locked->quote_id === null ? null : (string) $locked->quote_id,
                    'price_tier_id' => $locked->price_tier_id_snapshot === null ? null : (int) $locked->price_tier_id_snapshot,
                    'price_tier_code' => $locked->price_tier_code_snapshot,
                    'source' => data_get($pricing, 'source'),
                    'commercial_origin' => data_get($pricing, 'commercial_origin'),
                    'promotion_id' => data_get($pricing, 'promotion.id'),
                    'coupon_id' => data_get($pricing, 'coupon.id'),
                ],
                'collection' => [
                    'collected_amount' => (string) ($collection?->collected_amount ?? '0'),
                    'collection_count' => (int) ($collection?->collection_count ?? 0),
                ],
                'items' => $items->map(static fn (OrderItem $item): array => [
                    'order_item_id' => (int) $item->getKey(),
                    'product_id' => (int) $item->product_id,
                    'sku' => (string) $item->sku_snapshot,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                ])->all(),
            ];

            return $this->enqueue(
                self::ORDER_EVENT,
                $locked,
                $payload,
                'order',
                (int) $locked->getKey(),
            );
        }, 3);
    }

    public function publishCollection(
        Order $order,
        Invoice $invoice,
        Remittance $remittance,
    ): EnterpriseOutboxMessage {
        $channel = $this->channel($order);

        if ((int) $invoice->order_id !== (int) $order->getKey()) {
            throw new InvalidArgumentException('Commercial collection attribution invoice does not belong to the order.');
        }

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'fact' => 'collection_attribution',
            'source' => [
                'order_id' => (int) $order->getKey(),
                'invoice_id' => (int) $invoice->getKey(),
                'remittance_id' => (int) $remittance->getKey(),
                'collection_account_id' => (int) $remittance->collection_account_id,
                'store_id' => (int) $order->store_id,
                'channel' => $channel,
            ],
            'customer' => [
                'legacy_customer_id' => (int) $order->customer_id,
                'b2b_customer_id' => $order->b2b_customer_id === null ? null : (int) $order->b2b_customer_id,
                'b2c_customer_id' => $order->b2c_customer_id === null ? null : (int) $order->b2c_customer_id,
            ],
            'collection' => [
                'amount' => (string) $remittance->amount,
                'currency' => strtoupper((string) $remittance->currency),
                'status' => (string) $remittance->status,
            ],
        ];

        return $this->enqueue(
            self::COLLECTION_EVENT,
            $order,
            $payload,
            'remittance',
            (int) $remittance->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueue(
        string $eventType,
        Order $order,
        array $payload,
        string $aggregateType,
        int $aggregateId,
    ): EnterpriseOutboxMessage {
        $fingerprint = hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $event = new VersionedDomainEvent(
            id: $this->deterministicUuid(implode('|', [
                $eventType,
                (string) self::CONTRACT_VERSION,
                (string) $order->store_id,
                $aggregateType,
                (string) $aggregateId,
                $fingerprint,
            ])),
            type: $eventType,
            version: self::CONTRACT_VERSION,
            occurredAt: CarbonImmutable::now('UTC'),
            scope: [
                'store_id' => (int) $order->store_id,
                'channel' => $this->channel($order),
                'customer_id' => (int) $order->customer_id,
            ],
            correlationId: null,
            causationId: null,
            payload: [
                'schema' => 'foodex.commercial.profitability.v1',
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'fingerprint' => $fingerprint,
                'data' => $payload,
            ],
        );

        return $this->outbox->enqueue($event);
    }

    private function channel(Order $order): string
    {
        $channel = strtolower(trim((string) $order->channel));

        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new InvalidArgumentException('Commercial profitability feed supports only B2B/B2C orders.');
        }

        return $channel;
    }

    private function deterministicUuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
