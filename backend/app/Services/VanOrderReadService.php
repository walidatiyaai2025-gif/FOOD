<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class VanOrderReadService
{
    public function __construct(
        private readonly B2bAccountLedgerService $ledger,
    ) {}

    public function activeVanId(User $actor): int
    {
        $vanId = DB::table('van_assignments')
            ->leftJoin('drivers', 'drivers.id', '=', 'van_assignments.driver_id')
            ->where('van_assignments.status', 'active')
            ->where('van_assignments.effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('van_assignments.effective_until')
                    ->orWhere('van_assignments.effective_until', '>', now());
            })
            ->where(function ($query) use ($actor): void {
                $query->where('van_assignments.representative_user_id', $actor->getKey())
                    ->orWhere('drivers.user_id', $actor->getKey());
            })
            ->orderByRaw("CASE WHEN van_assignments.assignment_type = 'primary' THEN 0 ELSE 1 END")
            ->orderByDesc('van_assignments.effective_from')
            ->value('van_assignments.van_id');

        abort_unless(is_numeric($vanId), 403, 'No active Van assignment.');

        return (int) $vanId;
    }

    /** @return Builder<Order> */
    public function queryForActor(User $actor): Builder
    {
        $vanId = $this->activeVanId($actor);

        return Order::query()
            ->select([
                'orders.*',
                'van_order_scope.id as scoped_van_assignment_id',
                'van_order_scope.van_id as scoped_van_id',
            ])
            ->join('order_van_assignments as van_order_scope', 'van_order_scope.order_id', '=', 'orders.id')
            ->where('orders.channel', 'b2b')
            ->where('van_order_scope.van_id', $vanId)
            ->where('van_order_scope.status', 'active');
    }

    public function findOwned(User $actor, int $orderId): Order
    {
        return $this->queryForActor($actor)
            ->where('orders.id', $orderId)
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    public function summary(Order $order): array
    {
        $scopedAssignmentId = $order->getAttribute('scoped_van_assignment_id');
        $assignment = is_numeric($scopedAssignmentId)
            ? DB::table('order_van_assignments')
                ->where('id', (int) $scopedAssignmentId)
                ->where('order_id', $order->getKey())
                ->where('status', 'active')
                ->first(['id', 'van_id', 'assigned_at'])
            : null;

        $execution = $assignment === null
            ? null
            : DB::table('order_van_execution_states')
                ->where('order_van_assignment_id', $assignment->id)
                ->first(['status', 'failure_reason_code', 'last_transition_at']);

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'customer_type' => 'b2b',
            'customer_id' => $order->b2b_customer_id === null ? null : (int) $order->b2b_customer_id,
            'store_id' => (int) $order->store_id,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'grand_total' => (float) $order->grand_total,
            'assigned_at' => $assignment?->assigned_at,
            'van_execution_status' => $execution?->status,
            'van_failure_reason_code' => $execution?->failure_reason_code,
            'van_last_transition_at' => $execution?->last_transition_at,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(Order $order): array
    {
        $summary = $this->summary($order);

        $customer = $order->b2b_customer_id === null
            ? null
            : DB::table('b2b_customers')
                ->where('id', $order->b2b_customer_id)
                ->first(['id', 'legacy_customer_id', 'name', 'phone', 'email']);

        if ($customer === null && $order->customer_id !== null) {
            $legacy = DB::table('customers')
                ->where('id', $order->customer_id)
                ->first(['id', 'name', 'phone', 'email']);

            if ($legacy !== null) {
                $customer = (object) [
                    'id' => null,
                    'legacy_customer_id' => (int) $legacy->id,
                    'name' => $legacy->name,
                    'phone' => $legacy->phone,
                    'email' => $legacy->email,
                ];
            }
        }

        $items = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get([
                'id',
                'product_id',
                'sku_snapshot',
                'name_snapshot',
                'quantity',
                'unit_price',
                'line_total',
            ])
            ->map(static fn (object $item): array => [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku_snapshot,
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])
            ->values()
            ->all();

        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->first();

        $invoiceAmounts = $invoice instanceof Invoice
            ? $this->ledger->invoiceAmounts($invoice)
            : null;

        $payments = Payment::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (Payment $payment): array => [
                'id' => (int) $payment->getKey(),
                'invoice_id' => $payment->invoice_id === null ? null : (int) $payment->invoice_id,
                'provider' => (string) $payment->provider,
                'status' => (string) $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
                'created_at' => $payment->created_at?->toAtomString(),
            ])
            ->values()
            ->all();

        $collections = $invoice instanceof Invoice
            ? DB::table('collection_allocations')
                ->join(
                    'collection_transactions',
                    'collection_transactions.id',
                    '=',
                    'collection_allocations.collection_transaction_id',
                )
                ->where('collection_allocations.invoice_id', $invoice->getKey())
                ->where('collection_transactions.type', 'collection')
                ->orderBy('collection_transactions.id')
                ->get([
                    'collection_transactions.id',
                    'collection_transactions.payment_id',
                    'collection_transactions.status',
                    'collection_transactions.source',
                    'collection_transactions.created_at',
                    'collection_allocations.amount',
                    'collection_allocations.currency',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'payment_id' => $row->payment_id === null ? null : (int) $row->payment_id,
                    'status' => (string) $row->status,
                    'source' => (string) $row->source,
                    'amount' => (float) $row->amount,
                    'currency' => (string) $row->currency,
                    'collected_at' => $this->timestamp($row->created_at),
                ])
                ->values()
                ->all()
            : [];

        return [
            ...$summary,
            'payment_method' => $order->payment_method,
            'customer' => $customer === null ? null : [
                'id' => $customer->id === null ? null : (int) $customer->id,
                'legacy_customer_id' => $customer->legacy_customer_id === null
                    ? null
                    : (int) $customer->legacy_customer_id,
                'name' => (string) $customer->name,
                'phone' => $customer->phone === null ? null : (string) $customer->phone,
                'email' => $customer->email === null ? null : (string) $customer->email,
            ],
            'items' => $items,
            'invoice' => $invoice instanceof Invoice ? [
                'id' => (int) $invoice->getKey(),
                'invoice_number' => (string) $invoice->invoice_number,
                'status' => (string) $invoice->status,
                'currency' => (string) $invoice->currency,
                'total' => (float) ($invoiceAmounts['invoice_total'] ?? $invoice->total),
                'paid_amount' => (float) ($invoiceAmounts['paid_amount'] ?? 0),
                'outstanding_amount' => (float) ($invoiceAmounts['outstanding_amount'] ?? $invoice->total),
                'issued_at' => $invoice->issued_at === null
                    ? null
                    : CarbonImmutable::parse((string) $invoice->issued_at)->toAtomString(),
            ] : null,
            'payments' => $payments,
            'collections' => $collections,
            'timeline' => $this->timeline($order),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function timeline(Order $order): array
    {
        $events = [];

        if ($order->created_at !== null) {
            $events[] = [
                'stage' => 'placed',
                'status' => 'placed',
                'source' => 'order',
                'occurred_at' => $order->created_at->toAtomString(),
            ];
        }

        OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get(['id', 'from_status', 'to_status', 'note', 'created_at'])
            ->each(function (OrderStatusHistory $row) use (&$events): void {
                $events[] = [
                    'stage' => (string) $row->to_status,
                    'status' => (string) $row->to_status,
                    'source' => 'order_status',
                    'from_status' => $row->from_status,
                    'note' => $row->note,
                    'occurred_at' => $row->created_at?->toAtomString(),
                ];
            });

        DB::table('order_van_assignments')
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get(['id', 'van_id', 'status', 'source', 'reason', 'assigned_at', 'ended_at'])
            ->each(function (object $row) use (&$events): void {
                $events[] = [
                    'stage' => 'van_assigned',
                    'status' => (string) $row->status,
                    'source' => 'van_assignment',
                    'order_van_assignment_id' => (int) $row->id,
                    'van_id' => (int) $row->van_id,
                    'routing_source' => (string) $row->source,
                    'reason' => $row->reason === null ? null : (string) $row->reason,
                    'occurred_at' => $this->timestamp($row->assigned_at),
                    'ended_at' => $this->timestamp($row->ended_at),
                ];
            });

        DB::table('order_van_execution_states')
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get([
                'order_van_assignment_id',
                'van_id',
                'status',
                'failure_reason_code',
                'failure_note',
                'last_transition_at',
                'updated_at',
            ])
            ->each(function (object $row) use (&$events): void {
                $events[] = [
                    'stage' => (string) $row->status,
                    'status' => (string) $row->status,
                    'source' => 'van_execution',
                    'order_van_assignment_id' => (int) $row->order_van_assignment_id,
                    'van_id' => (int) $row->van_id,
                    'failure_reason_code' => $row->failure_reason_code === null
                        ? null
                        : (string) $row->failure_reason_code,
                    'failure_note' => $row->failure_note === null ? null : (string) $row->failure_note,
                    'occurred_at' => $this->timestamp($row->last_transition_at ?? $row->updated_at),
                ];
            });

        usort($events, static function (array $left, array $right): int {
            return strcmp(
                (string) ($left['occurred_at'] ?? ''),
                (string) ($right['occurred_at'] ?? ''),
            );
        });

        return $events;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toAtomString();
    }
}
