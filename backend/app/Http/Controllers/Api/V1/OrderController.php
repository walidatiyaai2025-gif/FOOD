<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDomainResolver;
use App\Services\CustomerOrderTimelineService;
use App\Services\DashboardOperationalNotifier;
use App\Services\InvoiceService;
use App\Services\OperationalTenantScope;
use App\Services\OrderDeliveryAddressSnapshotService;
use App\Services\OrderInventoryReservationService;
use App\Services\PlatformCustomerService;
use App\Services\RetailWholesaleReplenishmentService;
use Illuminate\Database\Eloquent\Builder;
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

    /** @return list<string> */
    public static function statusCodes(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** @return list<string> */
    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(self::statusCodes())],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', Rule::in(['b2b', 'b2c'])],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (
            ! $request->is('api/v1/b2b/*')
            && app(PlatformCustomerService::class)->isPlatformCustomer($user)
        ) {
            $query = $this->platformCustomerOrders($user)
                ->when(
                    isset($validated['store_id']),
                    fn ($query) => $query->where('store_id', (int) $validated['store_id']),
                )
                ->when(
                    isset($validated['channel']),
                    fn ($query) => $query->where('channel', (string) $validated['channel']),
                );
            $statusCounts = $this->statusCounts($query);

            $paginator = $query
                ->when(
                    isset($validated['status']),
                    fn ($query) => $query->where('status', $validated['status']),
                )
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
                    'all_total' => array_sum($statusCounts),
                    'scope' => 'platform_customer',
                    'status_codes' => self::statusCodes(),
                    'status_counts' => $statusCounts,
                ],
            ]);
        }

        [$customer, $channel] = $this->customerContext($request);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        $query = Order::query()
            ->where($customerColumn, $customer->getKey())
            ->where('channel', $channel);
        $statusCounts = $this->statusCounts($query);

        $paginator = $query
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status']),
            )
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
                'all_total' => array_sum($statusCounts),
                'scope' => $channel,
                'status_codes' => self::statusCodes(),
                'status_counts' => $statusCounts,
            ],
        ]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (
            ! $request->is('api/v1/b2b/*')
            && app(PlatformCustomerService::class)->isPlatformCustomer($user)
        ) {
            $model = $this->platformCustomerOrders($user)
                ->whereKey($order)
                ->firstOrFail();

            $this->assertRequestedOrderContext($request, $model);

            return response()->json($this->orderPayload($model, true));
        }

        [$customer, $channel] = $this->customerContext($request);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        $model = Order::query()
            ->whereKey($order)
            ->where($customerColumn, $customer->getKey())
            ->where('channel', $channel)
            ->firstOrFail();

        $this->assertRequestedOrderContext($request, $model);

        return response()->json($this->orderPayload($model, true));
    }

    public function transition(
        Request $request,
        int $order,
        AuditLogger $auditLogger,
        DashboardOperationalNotifier $dashboardNotifier,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::statusCodes()),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = Order::query()->findOrFail($order);
        abort_unless($this->canManageOrder($user, $model), 403);

        $previousStatus = (string) $model->status;
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

            $allowed = self::allowedTransitions($currentStatus);
            abort_unless(
                in_array($targetStatus, $allowed, true),
                409,
                "Order cannot transition from {$currentStatus} to {$targetStatus}.",
            );

            if ($targetStatus === 'cancelled') {
                app(OrderInventoryReservationService::class)->release($locked, $user);

                $activeAssignment = DriverAssignment::query()
                    ->where('order_id', $locked->getKey())
                    ->whereNotIn('status', [
                        'delivered',
                        'failed',
                        'cancelled',
                        'unassigned',
                        'reassigned',
                    ])
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($activeAssignment instanceof DriverAssignment) {
                    $assignmentBefore = $activeAssignment->toArray();
                    $activeAssignment->forceFill([
                        'status' => 'cancelled',
                        'completed_at' => now(),
                    ])->save();

                    $auditLogger->record(
                        'delivery.assignment.cancelled',
                        $user,
                        $activeAssignment,
                        $assignmentBefore,
                        [
                            ...$activeAssignment->fresh()->toArray(),
                            'reason' => 'order_cancelled',
                        ],
                        $request,
                    );
                }
            } elseif ($targetStatus === 'delivered') {
                app(OrderInventoryReservationService::class)->consume($locked, $user);
                app(RetailWholesaleReplenishmentService::class)->receive($locked, $user);
            }

            $locked->status = $targetStatus;
            $locked->save();

            // A dashboard-created pending order remains editable until it is accepted.
            // Confirmation is the commercial finalization point; customer checkout invoices
            // are already issued atomically by CheckoutController and this call is idempotent.
            if ($targetStatus === 'confirmed') {
                app(InvoiceService::class)->issueForOrder($locked, $user);
            } elseif ($targetStatus === 'cancelled') {
                app(InvoiceService::class)->voidForOrder(
                    $locked,
                    $user,
                    $note ?: 'order_cancelled',
                );
            }

            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'store_id' => (int) $locked->store_id,
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

        $fresh = $updated->fresh();
        $dashboardNotifier->orderStatusChanged($fresh, $previousStatus, (string) $fresh->status);

        return response()->json($this->orderPayload($fresh));
    }

    /** @return array{0: B2bCustomer|B2cCustomer, 1: string} */
    private function customerContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $resolver = app(CustomerDomainResolver::class);

        if ($request->is('api/v1/b2b/*')) {
            return [$resolver->b2bFromRequest($user, $request), 'b2b'];
        }

        return [$resolver->b2cFromRequest($user, $request), 'b2c'];
    }

    /** @return Builder<Order> */
    private function platformCustomerOrders(User $user): Builder
    {
        $b2bCustomerId = DB::table('b2b_customers')
            ->where('user_id', $user->getKey())
            ->value('id');

        $b2cCustomerIds = DB::table('b2c_customers')
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return Order::query()->where(function ($query) use ($b2bCustomerId, $b2cCustomerIds): void {
            if ($b2bCustomerId !== null) {
                $query->orWhere(function ($query) use ($b2bCustomerId): void {
                    $query->where('channel', 'b2b')
                        ->where('b2b_customer_id', (int) $b2bCustomerId);
                });
            }

            if ($b2cCustomerIds !== []) {
                $query->orWhere(function ($query) use ($b2cCustomerIds): void {
                    $query->where('channel', 'b2c')
                        ->whereIn('b2c_customer_id', $b2cCustomerIds);
                });
            }

            if ($b2bCustomerId === null && $b2cCustomerIds === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    private function assertRequestedOrderContext(Request $request, Order $order): void
    {
        $requestedStoreId = $request->input('store_id')
            ?? $request->query('store_id')
            ?? $request->header('X-FOODEX-Store-ID');

        if (is_numeric($requestedStoreId) && (int) $requestedStoreId > 0) {
            abort_unless((int) $requestedStoreId === (int) $order->store_id, 404);
        }

        $requestedChannel = strtolower(trim((string) (
            $request->input('channel')
            ?? $request->query('channel')
            ?? $request->header('X-FOODEX-Customer-Domain', '')
        )));

        if ($requestedChannel !== '') {
            abort_unless(in_array($requestedChannel, ['b2b', 'b2c'], true), 404);
            abort_unless($requestedChannel === strtolower((string) $order->channel), 404);
        }
    }

    private function canManageOrder(User $user, Order $order): bool
    {
        return in_array(
            (int) $order->store_id,
            app(OperationalTenantScope::class)->allowedStoreIds(
                $user,
                'orders.manage',
                (string) $order->channel,
            ),
            true,
        );
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];
        foreach (self::statusCodes() as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    private function orderPayload(Order $order, bool $includeTimeline = false): array
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
                'quantity_conversion_factor' => (float) ($item->quantity_conversion_factor ?? 1),
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])
            ->values()
            ->all();

        $history = OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderStatusHistory $entry): array => [
                'id' => (int) $entry->getKey(),
                'from_status' => $entry->from_status,
                'to_status' => (string) $entry->to_status,
                'created_at' => $entry->created_at?->toAtomString(),
            ])
            ->values()
            ->all();

        $payment = Payment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();

        $store = DB::table('stores')
            ->where('id', $order->store_id)
            ->first(['id', 'code', 'name', 'logo_path']);

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'store_id' => (int) $order->store_id,
            'store' => $store === null ? null : [
                'id' => (int) $store->id,
                'code' => (string) $store->code,
                'name' => (string) $store->name,
                'logo_url' => $store->logo_path === null
                    ? null
                    : url('/'.ltrim((string) $store->logo_path, '/')),
            ],
            'address_id' => $order->address_id === null ? null : (int) $order->address_id,
            'delivery_address' => app(OrderDeliveryAddressSnapshotService::class)->payload($order),
            'requested_delivery_date' => $order->requested_delivery_date,
            'channel' => (string) $order->channel,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $order->payment_method,
            'item_count' => count($items),
            'next_statuses' => self::allowedTransitions((string) $order->status),
            'items' => $items,
            'status_history' => $history,
            'timeline' => $includeTimeline
                ? app(CustomerOrderTimelineService::class)->forOrder($order)
                : null,
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
