<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DriverOrderService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
        private readonly OrderInventoryReservationService $reservations,
    ) {}

    /** @return list<string> */
    public function availableStatuses(DriverAssignment $assignment, Order $order): array
    {
        $assignmentStatus = (string) $assignment->status;
        $orderStatus = (string) $order->status;

        if ($assignment->completed_at !== null
            || in_array($orderStatus, ['cancelled', 'delivered'], true)) {
            return [];
        }

        return match ($assignmentStatus) {
            'assigned' => ['accepted'],
            'accepted' => $orderStatus === 'ready' ? ['picked_up'] : [],
            'picked_up' => $orderStatus === 'ready' ? ['out_for_delivery'] : [],
            'out_for_delivery' => $orderStatus === 'out_for_delivery' ? ['delivered', 'failed'] : [],
            'failed' => $orderStatus === 'failed' ? ['out_for_delivery'] : [],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function payload(DriverAssignment $assignment): array
    {
        $order = Order::query()->findOrFail($assignment->order_id);
        abort_unless(
            (int) $order->store_id === (int) $assignment->store_id
                && strtolower((string) $order->channel) === strtolower((string) $assignment->assignment_type),
            404,
        );

        $channel = strtolower((string) $assignment->assignment_type);
        $customerTable = $channel === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $domainCustomerId = $order->getAttribute($customerColumn);

        $customer = $domainCustomerId === null
            ? null
            : DB::table($customerTable)
                ->where('id', $domainCustomerId)
                ->first(['name', 'phone', 'email']);

        $address = $order->address_id === null
            ? null
            : DB::table('addresses')
                ->where('id', $order->address_id)
                ->first([
                    'label',
                    'line1',
                    'line2',
                    'city',
                    'area',
                    'country_code',
                    'latitude',
                    'longitude',
                ]);

        $payment = DB::table('payments')
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->first(['provider', 'status', 'amount', 'currency']);

        $store = DB::table('stores')
            ->where('id', $order->store_id)
            ->first(['name', 'code']);

        $items = OrderItem::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): array => [
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku_snapshot,
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $assignment->getKey(),
            'driver_id' => (int) $assignment->driver_id,
            'order_id' => (int) $order->getKey(),
            'store_id' => (int) $order->store_id,
            'assignment_type' => $channel,
            'status' => (string) $assignment->status,
            'assigned_at' => $assignment->assigned_at,
            'completed_at' => $assignment->completed_at,
            'available_statuses' => $this->availableStatuses($assignment, $order),
            'order' => [
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'subtotal' => (float) $order->subtotal,
                'discount_total' => (float) $order->discount_total,
                'delivery_total' => (float) $order->delivery_total,
                'grand_total' => (float) $order->grand_total,
                'payment_method' => (string) ($order->payment_method ?? ''),
                'customer_note' => $order->customer_note,
                'created_at' => $order->created_at,
                'store' => [
                    'name' => $store?->name,
                    'code' => $store?->code,
                ],
                'customer' => [
                    'name' => $customer?->name,
                    'phone' => $customer?->phone,
                    'email' => $customer?->email,
                ],
                'address' => $address === null ? null : [
                    'label' => $address->label,
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'area' => $address->area,
                    'country_code' => $address->country_code,
                    'latitude' => $address->latitude === null ? null : (float) $address->latitude,
                    'longitude' => $address->longitude === null ? null : (float) $address->longitude,
                ],
                'payment' => $payment === null ? null : [
                    'provider' => $payment->provider,
                    'status' => $payment->status,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                ],
                'items' => $items,
            ],
        ];
    }

    public function transition(
        DriverAssignment $assignment,
        Driver $driver,
        User $actor,
        string $targetStatus,
        ?string $note,
        Request $request,
    ): DriverAssignment {
        $beforeAssignment = (string) $assignment->status;
        $beforeOrder = null;
        $afterOrder = null;

        $updated = DB::transaction(function () use (
            $assignment,
            $driver,
            $actor,
            $targetStatus,
            $note,
            $request,
            &$beforeOrder,
            &$afterOrder,
        ): DriverAssignment {
            $locked = DriverAssignment::query()
                ->whereKey($assignment->getKey())
                ->where('driver_id', $driver->getKey())
                ->where('assignment_type', strtolower((string) $driver->driver_type))
                ->lockForUpdate()
                ->firstOrFail();

            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            abort_unless(
                (int) $order->store_id === (int) $locked->store_id
                    && (int) $order->store_id === (int) $driver->store_id
                    && strtolower((string) $order->channel) === strtolower((string) $locked->assignment_type),
                404,
            );

            $allowed = $this->availableStatuses($locked, $order);
            abort_unless(
                in_array($targetStatus, $allowed, true),
                409,
                'The delivery action is not available for the current order state.',
            );

            $beforeOrder = (string) $order->status;

            if ($targetStatus === 'out_for_delivery') {
                abort_unless(in_array((string) $order->status, ['ready', 'failed'], true), 409);
                $this->updateOrderStatus($order, $actor, 'out_for_delivery', $note, $request);
                $afterOrder = 'out_for_delivery';
            } elseif ($targetStatus === 'delivered') {
                abort_unless((string) $order->status === 'out_for_delivery', 409);
                $this->reservations->consume($order, $actor);
                $this->updateOrderStatus($order, $actor, 'delivered', $note, $request);
                $afterOrder = 'delivered';
            } elseif ($targetStatus === 'failed') {
                abort_unless((string) $order->status === 'out_for_delivery', 409);
                $this->updateOrderStatus($order, $actor, 'failed', $note, $request);
                $afterOrder = 'failed';
            } elseif ($targetStatus === 'picked_up') {
                abort_unless((string) $order->status === 'ready', 409);
            }

            $locked->status = $targetStatus;
            if ($targetStatus === 'delivered') {
                $locked->completed_at = now();
            } elseif ($targetStatus !== 'delivered') {
                $locked->completed_at = null;
            }
            $locked->save();

            if ($note !== null && trim($note) !== '') {
                DB::table('delivery_proofs')->insert([
                    'driver_assignment_id' => $locked->getKey(),
                    'proof_type' => $targetStatus === 'failed' ? 'failure_note' : 'status_note',
                    'file_path' => null,
                    'otp_hash' => null,
                    'note' => trim($note),
                    'captured_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                'delivery.assignment.status_changed',
                $actor,
                $locked,
                ['status' => (string) $assignment->status],
                ['status' => $targetStatus, 'note' => $note],
                $request,
            );

            return $locked;
        }, 3);

        $fresh = $updated->fresh();
        $order = Order::query()->findOrFail($fresh->order_id);
        $this->notifier->deliveryChanged($order, (string) $fresh->status);

        if ($beforeOrder !== null && $afterOrder !== null && $beforeOrder !== $afterOrder) {
            $this->notifier->orderStatusChanged($order, $beforeOrder, $afterOrder);
        }

        return $fresh;
    }

    private function updateOrderStatus(
        Order $order,
        User $actor,
        string $status,
        ?string $note,
        Request $request,
    ): void {
        $from = (string) $order->status;
        $order->status = $status;
        $order->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'store_id' => (int) $order->store_id,
            'user_id' => $actor->getKey(),
            'from_status' => $from,
            'to_status' => $status,
            'note' => $note,
        ]);

        $this->audit->record(
            'order.status_changed',
            $actor,
            $order,
            ['status' => $from],
            ['status' => $status, 'source' => 'driver', 'note' => $note],
            $request,
        );
    }
}
