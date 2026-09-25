<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['delivered', 'failed'],
        'failed' => ['out_for_delivery', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        [$customer, $channel] = $this->customerContext($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('channel', $channel)
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Order $order): array => $this->orderPayload($order))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        [$customer, $channel] = $this->customerContext($request);

        $model = Order::query()
            ->whereKey($order)
            ->where('customer_id', $customer->getKey())
            ->where('channel', $channel)
            ->firstOrFail();

        return response()->json($this->orderPayload($model));
    }

    public function transition(
        Request $request,
        int $order,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(array_keys(self::TRANSITIONS)),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = Order::query()->findOrFail($order);
        abort_unless($this->canManageOrder($user, $model), 403);

        $targetStatus = (string) $validated['status'];
        $note = isset($validated['note']) ? (string) $validated['note'] : null;

        /** @var Order $updated */
        $updated = DB::transaction(function () use (
            $model,
            $user,
            $targetStatus,
            $note,
            $auditLogger,
            $request,
        ): Order {
            $locked = Order::query()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $currentStatus = (string) $locked->status;

            if ($currentStatus === $targetStatus) {
                return $locked;
            }

            $allowed = self::TRANSITIONS[$currentStatus] ?? [];
            abort_unless(
                in_array($targetStatus, $allowed, true),
                409,
                "Order cannot transition from {$currentStatus} to {$targetStatus}.",
            );

            if ($targetStatus === 'cancelled') {
                $this->releaseReservations($locked, $user);
            } elseif ($targetStatus === 'delivered') {
                $this->consumeReservations($locked, $user);
            }

            $locked->status = $targetStatus;
            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'user_id' => $user->getKey(),
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'note' => $note,
            ]);

            $auditLogger->record(
                'order.status_changed',
                $user,
                $locked,
                ['status' => $currentStatus],
                ['status' => $targetStatus, 'note' => $note],
                $request,
            );

            return $locked;
        }, 3);

        return response()->json($this->orderPayload($updated->fresh()));
    }

    /** @return array{0: Customer, 1: string} */
    private function customerContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $customer = Customer::query()->where('user_id', $user->getKey())->first();
        abort_unless($customer instanceof Customer, 403, 'Customer profile is required.');

        $channel = strtolower((string) $customer->type);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403, 'Unsupported customer channel.');

        return [$customer, $channel];
    }

    private function canManageOrder(User $user, Order $order): bool
    {
        if (! $user->hasPermission('orders.manage', (int) $order->store_id)
            && ! $user->hasPermission('orders.manage')) {
            return false;
        }

        $channel = strtolower((string) $order->channel);
        $config = (array) config("admin.channels.{$channel}", []);
        $globalRoles = array_values((array) ($config['global_roles'] ?? []));
        $storeRoles = array_values((array) ($config['store_roles'] ?? []));

        $hasGlobalChannelRole = $user->roles()
            ->whereIn('roles.code', $globalRoles)
            ->exists();

        if ($hasGlobalChannelRole && $user->hasPermission('orders.manage')) {
            return true;
        }

        if ($storeRoles === []) {
            return false;
        }

        $hasStoreChannelRole = $user->storeRoleAssignments()
            ->where('store_id', (int) $order->store_id)
            ->whereHas('role', fn ($query) => $query->whereIn('roles.code', $storeRoles))
            ->exists();

        return $hasStoreChannelRole
            && $user->hasPermission('orders.manage', (int) $order->store_id);
    }

    private function releaseReservations(Order $order, User $user): void
    {
        $reservations = StockMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('type', 'reserve')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $inventory = DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                continue;
            }

            $quantity = (float) $reservation->quantity;
            $reserved = max(0.0, (float) $inventory->reserved_quantity - $quantity);

            DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->update([
                    'reserved_quantity' => $reserved,
                    'updated_at' => now(),
                ]);

            StockMovement::query()->create([
                'inventory_id' => $reservation->inventory_id,
                'user_id' => $user->getKey(),
                'type' => 'release',
                'quantity' => $quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => 'order_cancelled',
            ]);
        }
    }

    private function consumeReservations(Order $order, User $user): void
    {
        $reservations = StockMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('type', 'reserve')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $inventory = DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->lockForUpdate()
                ->first();

            abort_if($inventory === null, 409, 'Reserved inventory no longer exists.');

            $quantity = (float) $reservation->quantity;
            $onHand = (float) $inventory->quantity;
            $reserved = (float) $inventory->reserved_quantity;

            abort_if(
                $quantity > $onHand || $quantity > $reserved,
                409,
                'Reserved inventory is inconsistent with the order.',
            );

            DB::table('inventories')
                ->where('id', $reservation->inventory_id)
                ->update([
                    'quantity' => $onHand - $quantity,
                    'reserved_quantity' => $reserved - $quantity,
                    'updated_at' => now(),
                ]);

            StockMovement::query()->create([
                'inventory_id' => $reservation->inventory_id,
                'user_id' => $user->getKey(),
                'type' => 'sale',
                'quantity' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => 'order_delivered',
            ]);
        }
    }

    private function orderPayload(Order $order): array
    {
        $items = OrderItem::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): array => [
                'id' => (int) $item->getKey(),
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku_snapshot,
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])
            ->values()
            ->all();

        $history = OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderStatusHistory $entry): array => [
                'id' => (int) $entry->getKey(),
                'from_status' => $entry->from_status,
                'to_status' => (string) $entry->to_status,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toAtomString(),
            ])
            ->values()
            ->all();

        $payment = Payment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'store_id' => (int) $order->store_id,
            'address_id' => $order->address_id === null ? null : (int) $order->address_id,
            'channel' => (string) $order->channel,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $order->payment_method,
            'items' => $items,
            'status_history' => $history,
            'payment' => $payment instanceof Payment ? [
                'id' => (int) $payment->getKey(),
                'provider' => (string) $payment->provider,
                'status' => (string) $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
            ] : null,
            'created_at' => $order->created_at?->toAtomString(),
        ];
    }
}
