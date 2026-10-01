<?php

namespace App\Domain\Assistant\Operations;

use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantOperationsReadService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const LATE_ORDER_MINUTES = 60;

    private const LATE_ORDER_STATUSES = [
        'pending',
        'confirmed',
        'processing',
        'ready',
        'out_for_delivery',
    ];

    private const ACTIVE_ASSIGNMENT_STATUSES = [
        'assigned',
        'accepted',
        'picked_up',
        'out_for_delivery',
    ];

    private const ONLINE_SECONDS = 45;

    private const STALE_SECONDS = 180;

    private const MAX_ROWS = 50;

    public function __construct(private readonly OperationalTenantScope $scope) {}

    public function execute(string $tool, AssistantToolRequest $request): AssistantToolResult
    {
        return match ($tool) {
            'orders.late' => $this->lateOrders($request),
            'orders.cancelled' => $this->cancelledOrders($request),
            'cancellations.summary' => $this->cancellationsSummary($request),
            'drivers.status' => $this->driverStatus($request),
            'drivers.assignments' => $this->driverAssignments($request),
            'inventory.alerts' => $this->inventoryAlerts($request),
            'brief.daily' => $this->dailyBrief($request),
            default => throw new \InvalidArgumentException("Unsupported Assistant operations tool [{$tool}]."),
        };
    }

    private function lateOrders(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'orders.view');
        $asOf = CarbonImmutable::now(self::TIMEZONE);
        $cutoff = $asOf->subMinutes(self::LATE_ORDER_MINUTES)->utc()->toDateTimeString();

        $query = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->whereIn('orders.status', self::LATE_ORDER_STATUSES)
            ->where('orders.created_at', '<=', $cutoff)
            ->when(
                $this->channel($request) !== null,
                fn (Builder $builder) => $builder->where('orders.channel', $this->channel($request)),
            );

        $rows = (clone $query)
            ->orderBy('orders.created_at')
            ->limit(self::MAX_ROWS)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.store_id',
                'stores.name as store',
                'orders.channel',
                'orders.status',
                'orders.created_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'store_id' => (int) $row->store_id,
                'store' => (string) $row->store,
                'channel' => strtolower((string) $row->channel),
                'status' => (string) $row->status,
                'created_at' => $this->localDateTime($row->created_at),
                'age_minutes' => max(
                    0,
                    (int) CarbonImmutable::parse((string) $row->created_at, 'UTC')->diffInMinutes($asOf->utc()),
                ),
            ])
            ->all();

        return new AssistantToolResult(data: [
            'as_of' => $asOf->toIso8601String(),
            'late_after_minutes' => self::LATE_ORDER_MINUTES,
            'late_statuses' => self::LATE_ORDER_STATUSES,
            'late_orders' => (clone $query)->count('orders.id'),
            'rows' => $rows,
            'truncated' => (clone $query)->count('orders.id') > self::MAX_ROWS,
            'store_ids' => $storeIds,
        ]);
    }

    private function cancelledOrders(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'orders.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);

        $query = $this->ordersInWindow($storeIds, $request, $fromUtc, $toUtc)
            ->where('orders.status', 'cancelled');

        $rows = (clone $query)
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->orderByDesc('orders.created_at')
            ->limit(self::MAX_ROWS)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.store_id',
                'stores.name as store',
                'orders.channel',
                'orders.grand_total',
                'orders.created_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'store_id' => (int) $row->store_id,
                'store' => (string) $row->store,
                'channel' => strtolower((string) $row->channel),
                'grand_total' => round((float) $row->grand_total, 3),
                'created_at' => $this->localDateTime($row->created_at),
            ])
            ->all();

        return new AssistantToolResult(data: [
            'from' => $from,
            'to' => $to,
            'cancelled_orders' => (clone $query)->count('orders.id'),
            'rows' => $rows,
            'truncated' => (clone $query)->count('orders.id') > self::MAX_ROWS,
            'store_ids' => $storeIds,
        ]);
    }

    private function cancellationsSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'orders.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $orders = $this->ordersInWindow($storeIds, $request, $fromUtc, $toUtc);

        $total = (clone $orders)->count('orders.id');
        $cancelled = (clone $orders)->where('orders.status', 'cancelled')->count('orders.id');
        $cancelledValue = round(
            (float) (clone $orders)->where('orders.status', 'cancelled')->sum('orders.grand_total'),
            3,
        );

        return new AssistantToolResult(data: [
            'from' => $from,
            'to' => $to,
            'orders' => $total,
            'cancelled_orders' => $cancelled,
            'cancellation_rate_percent' => $total === 0 ? 0.0 : round(($cancelled / $total) * 100, 1),
            'cancelled_value' => $cancelledValue,
            'store_ids' => $storeIds,
        ]);
    }

    private function driverStatus(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->driverStoreIds($user, $request);
        $now = CarbonImmutable::now('UTC');
        $onlineCutoff = $now->subSeconds(self::ONLINE_SECONDS);
        $staleCutoff = $now->subSeconds(self::STALE_SECONDS);

        $rows = DB::table('drivers')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->leftJoin('driver_current_locations as locations', 'locations.driver_id', '=', 'drivers.id')
            ->whereIn('drivers.store_id', $storeIds)
            ->when(
                $this->channel($request) !== null,
                fn (Builder $builder) => $builder->where('drivers.driver_type', $this->channel($request)),
            )
            ->orderBy('drivers.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'drivers.id',
                'drivers.store_id',
                'drivers.driver_type',
                'drivers.is_active',
                'drivers.is_available',
                'users.name as driver_name',
                'locations.received_at',
                'locations.active_assignment_id',
            ])
            ->map(function (object $row) use ($onlineCutoff, $staleCutoff): array {
                $receivedAt = $row->received_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->received_at, 'UTC');

                $locationStatus = $receivedAt === null
                    ? 'offline'
                    : ($receivedAt->greaterThanOrEqualTo($onlineCutoff)
                        ? 'online'
                        : ($receivedAt->greaterThanOrEqualTo($staleCutoff) ? 'stale' : 'offline'));

                $activeAssignment = DB::table('driver_assignments')
                    ->where('driver_id', (int) $row->id)
                    ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
                    ->latest('id')
                    ->first(['id', 'order_id', 'status']);

                $operationalStatus = ! (bool) $row->is_active
                    ? 'inactive'
                    : ($activeAssignment !== null
                        ? 'busy'
                        : ((bool) $row->is_available ? 'available' : 'unavailable'));

                return [
                    'driver_id' => (int) $row->id,
                    'driver_name' => (string) $row->driver_name,
                    'store_id' => (int) $row->store_id,
                    'channel' => strtolower((string) $row->driver_type),
                    'operational_status' => $operationalStatus,
                    'location_status' => $locationStatus,
                    'is_active' => (bool) $row->is_active,
                    'is_available' => (bool) $row->is_available,
                    'last_seen_at' => $receivedAt?->setTimezone(self::TIMEZONE)->toIso8601String(),
                    'active_assignment' => $activeAssignment === null ? null : [
                        'id' => (int) $activeAssignment->id,
                        'order_id' => (int) $activeAssignment->order_id,
                        'status' => (string) $activeAssignment->status,
                    ],
                ];
            })
            ->all();

        return new AssistantToolResult(data: [
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
            'freshness' => [
                'online_seconds' => self::ONLINE_SECONDS,
                'stale_seconds' => self::STALE_SECONDS,
            ],
            'drivers' => $rows,
            'driver_count' => count($rows),
            'online' => count(array_filter($rows, static fn (array $row): bool => $row['location_status'] === 'online')),
            'available' => count(array_filter($rows, static fn (array $row): bool => $row['operational_status'] === 'available')),
            'busy' => count(array_filter($rows, static fn (array $row): bool => $row['operational_status'] === 'busy')),
            'store_ids' => $storeIds,
        ]);
    }

    private function driverAssignments(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->driverStoreIds($user, $request);
        $status = $this->stringValue($request->entities['status'] ?? null);

        $query = DB::table('driver_assignments')
            ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
            ->whereIn('driver_assignments.store_id', $storeIds)
            ->when(
                $this->channel($request) !== null,
                fn (Builder $builder) => $builder->where('driver_assignments.assignment_type', $this->channel($request)),
            )
            ->when(
                $status !== null,
                fn (Builder $builder) => $builder->where('driver_assignments.status', $status),
                fn (Builder $builder) => $builder->whereIn('driver_assignments.status', self::ACTIVE_ASSIGNMENT_STATUSES),
            );

        $rows = (clone $query)
            ->orderByDesc('driver_assignments.assigned_at')
            ->limit(self::MAX_ROWS)
            ->get([
                'driver_assignments.id',
                'driver_assignments.driver_id',
                'driver_assignments.order_id',
                'driver_assignments.store_id',
                'driver_assignments.assignment_type',
                'driver_assignments.status',
                'driver_assignments.assigned_at',
                'driver_assignments.completed_at',
                'users.name as driver_name',
                'orders.order_number',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'driver_id' => (int) $row->driver_id,
                'driver_name' => (string) $row->driver_name,
                'order_id' => (int) $row->order_id,
                'order_number' => (string) $row->order_number,
                'store_id' => (int) $row->store_id,
                'channel' => strtolower((string) $row->assignment_type),
                'status' => (string) $row->status,
                'assigned_at' => $row->assigned_at === null ? null : $this->localDateTime($row->assigned_at),
                'completed_at' => $row->completed_at === null ? null : $this->localDateTime($row->completed_at),
            ])
            ->all();

        return new AssistantToolResult(data: [
            'assignments' => $rows,
            'assignment_count' => (clone $query)->count('driver_assignments.id'),
            'truncated' => (clone $query)->count('driver_assignments.id') > self::MAX_ROWS,
            'store_ids' => $storeIds,
        ]);
    }

    private function inventoryAlerts(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'inventory.view');
        $threshold = max(0.0, (float) config('admin.low_stock_threshold', 12));

        $query = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->whereIn('warehouses.store_id', $storeIds)
            ->where('warehouses.is_active', true)
            ->whereRaw('(inventories.quantity - inventories.reserved_quantity) <= ?', [$threshold]);

        $rows = (clone $query)
            ->orderByRaw('(inventories.quantity - inventories.reserved_quantity) asc')
            ->orderBy('inventories.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'inventories.id',
                'inventories.warehouse_id',
                'inventories.product_id',
                'inventories.quantity',
                'inventories.reserved_quantity',
                'warehouses.store_id',
                'warehouses.name as warehouse',
                'products.sku',
                'products.name as product',
            ])
            ->map(static fn (object $row): array => [
                'inventory_id' => (int) $row->id,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse' => (string) $row->warehouse,
                'store_id' => (int) $row->store_id,
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'product' => (string) $row->product,
                'quantity' => round((float) $row->quantity, 3),
                'reserved_quantity' => round((float) $row->reserved_quantity, 3),
                'available_quantity' => round((float) $row->quantity - (float) $row->reserved_quantity, 3),
            ])
            ->all();

        return new AssistantToolResult(data: [
            'threshold' => $threshold,
            'alerts' => $rows,
            'alert_count' => (clone $query)->count('inventories.id'),
            'truncated' => (clone $query)->count('inventories.id') > self::MAX_ROWS,
            'store_ids' => $storeIds,
        ]);
    }

    private function dailyBrief(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $this->assertAssistantContext($user, $request);

        $sections = [
            'late_orders' => $this->hasScope($user, $request, 'orders.view')
                ? $this->lateOrders($request)->data
                : null,
            'cancellations' => $this->hasScope($user, $request, 'orders.view')
                ? $this->cancellationsSummary($request)->data
                : null,
            'drivers' => $this->hasDriverScope($user, $request)
                ? $this->driverStatus($request)->data
                : null,
            'inventory' => $this->hasScope($user, $request, 'inventory.view')
                ? $this->inventoryAlerts($request)->data
                : null,
        ];

        return new AssistantToolResult(data: [
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
            'sections' => $sections,
            'available_sections' => array_keys(array_filter(
                $sections,
                static fn (?array $section): bool => $section !== null,
            )),
        ]);
    }

    private function actor(AssistantToolRequest $request): User
    {
        $user = User::query()->whereKey($request->actorUserId)->where('is_active', true)->first();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function storeIds(
        User $user,
        AssistantToolRequest $request,
        string $permission,
        ?string $channelOverride = null,
    ): array {
        $allowed = $this->intersectStoreIds(
            $user,
            $permission,
            $channelOverride ?? $this->channel($request),
        );

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $allowed, true), 404);

            return [$request->storeId];
        }

        abort_if($allowed === [], 403);

        return $allowed;
    }

    /**
     * @return list<int>
     */
    private function driverStoreIds(User $user, AssistantToolRequest $request): array
    {
        $channel = $this->channel($request);
        $ids = $channel === null
            ? array_merge(
                $this->intersectStoreIds($user, 'drivers.b2b.view', 'b2b'),
                $this->intersectStoreIds($user, 'drivers.b2c.view', 'b2c'),
            )
            : $this->intersectStoreIds($user, "drivers.{$channel}.view", $channel);

        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $ids, true), 404);

            return [$request->storeId];
        }

        abort_if($ids === [], 403);

        return $ids;
    }

    private function assertAssistantContext(User $user, AssistantToolRequest $request): void
    {
        $assistantStores = $this->scope->allowedStoreIds($user, 'assistant.use', $this->channel($request));

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $assistantStores, true), 404);

            return;
        }

        abort_if($assistantStores === [], 403);
    }

    private function hasScope(User $user, AssistantToolRequest $request, string $permission): bool
    {
        $ids = $this->intersectStoreIds($user, $permission, $this->channel($request));

        return $request->storeId === null
            ? $ids !== []
            : in_array($request->storeId, $ids, true);
    }

    private function hasDriverScope(User $user, AssistantToolRequest $request): bool
    {
        $channel = $this->channel($request);
        $ids = $channel === null
            ? array_merge(
                $this->intersectStoreIds($user, 'drivers.b2b.view', 'b2b'),
                $this->intersectStoreIds($user, 'drivers.b2c.view', 'b2c'),
            )
            : $this->intersectStoreIds($user, "drivers.{$channel}.view", $channel);

        return $request->storeId === null
            ? $ids !== []
            : in_array($request->storeId, $ids, true);
    }

    /**
     * @return list<int>
     */
    private function intersectStoreIds(User $user, string $permission, ?string $channel): array
    {
        $ids = array_values(array_intersect(
            $this->scope->allowedStoreIds($user, 'assistant.use', $channel),
            $this->scope->allowedStoreIds($user, $permission, $channel),
        ));
        sort($ids);

        return $ids;
    }

    private function ordersInWindow(
        array $storeIds,
        AssistantToolRequest $request,
        string $fromUtc,
        string $toUtc,
    ): Builder {
        return DB::table('orders')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when(
                $this->channel($request) !== null,
                fn (Builder $builder) => $builder->where('orders.channel', $this->channel($request)),
            );
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function dateWindow(AssistantToolRequest $request): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $from = $this->stringValue($request->entities['from'] ?? null) ?? $today;
        $to = $this->stringValue($request->entities['to'] ?? null) ?? $from;

        $fromDate = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, self::TIMEZONE)->endOfDay();

        if ($toDate->lessThan($fromDate)) {
            throw new \InvalidArgumentException('Assistant operations tool date range is invalid.');
        }

        if ($fromDate->diffInDays($toDate) > 366) {
            throw new \InvalidArgumentException('Assistant operations tool date range exceeds 366 days.');
        }

        return [
            $fromDate->utc()->toDateTimeString(),
            $toDate->utc()->toDateTimeString(),
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ];
    }

    private function channel(AssistantToolRequest $request): ?string
    {
        $channel = $request->channel ?? $this->stringValue($request->entities['channel'] ?? null);
        if ($channel === null) {
            return null;
        }

        $channel = strtolower($channel);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new \InvalidArgumentException('Assistant operations tool channel must be b2b or b2c.');
        }

        return $channel;
    }

    private function localDateTime(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value, 'UTC')
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d H:i');
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
