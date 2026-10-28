<?php

namespace App\Services;

use App\Models\CollectionAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Driver collections are recorded against the collection account and allocated
 * invoice, not inferred from delivery status or the order's payment method.
 */
final class DriverCollectionReportService
{
    public function __construct(private readonly DeliverySettlementService $settlements) {}

    /** @return array{total_collected: float, orders: list<array<string, mixed>>, has_more: bool} */
    public function forAccount(CollectionAccount $account, int $limit = 100): array
    {
        abort_unless((string) $account->actor_type === 'driver', 403);
        $limit = max(1, min(100, $limit));
        $base = $this->baseQuery($account);

        $total = (float) (clone $base)->sum('alloc.amount');
        $rows = (clone $base)
            ->select([
                'orders.id as order_id',
                'orders.order_number',
                'orders.currency',
            ])
            ->selectRaw('SUM(alloc.amount) as collected_amount')
            ->selectRaw('MAX(ct.created_at) as last_collected_at')
            ->groupBy('orders.id', 'orders.order_number', 'orders.currency')
            ->orderByDesc('last_collected_at')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $orders = [];
        foreach ($rows->take($limit) as $row) {
            $order = Order::query()
                ->whereKey((int) $row->order_id)
                ->where('store_id', $account->store_id)
                ->first();
            if (! $order instanceof Order) {
                continue;
            }

            $invoice = Invoice::query()
                ->where('order_id', $order->getKey())
                ->whereIn('status', ['issued', 'reissued'])
                ->orderByDesc('revision')
                ->orderByDesc('id')
                ->first();
            $payment = Payment::query()->where('order_id', $order->getKey())->latest('id')->first();
            $settlement = $this->settlements->summarize($order, $invoice, $payment);

            $orders[] = [
                'order_id' => (int) $row->order_id,
                'order_number' => (string) $row->order_number,
                'currency' => (string) $row->currency,
                'order_total' => (float) $settlement['order_total'],
                'collected_amount' => round((float) $row->collected_amount, 3),
                'paid_amount' => (float) $settlement['paid_amount'],
                'remaining_amount' => (float) $settlement['remaining_amount'],
                'payment_state' => (string) $settlement['payment_state'],
                'last_collected_at' => (string) $row->last_collected_at,
            ];
        }

        return [
            'total_collected' => round($total, 3),
            'orders' => $orders,
            'has_more' => $hasMore,
        ];
    }

    private function baseQuery(CollectionAccount $account): Builder
    {
        return DB::table('collection_allocations as alloc')
            ->join('collection_transactions as ct', 'ct.id', '=', 'alloc.collection_transaction_id')
            ->join('invoices as invoices', 'invoices.id', '=', 'alloc.invoice_id')
            ->join('orders as orders', 'orders.id', '=', 'invoices.order_id')
            ->where('ct.collection_account_id', $account->getKey())
            ->where('ct.type', 'collection')
            ->where('ct.status', 'posted')
            ->where('orders.store_id', $account->store_id)
            ->where('alloc.currency', strtoupper((string) $account->currency))
            ->where('orders.currency', strtoupper((string) $account->currency))
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('collection_transactions as reversal')
                    ->whereColumn('reversal.reversal_of_id', 'ct.id')
                    ->where('reversal.status', 'posted');
            });
    }
}
