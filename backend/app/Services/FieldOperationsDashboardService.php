<?php

namespace App\Services;

use App\Models\FleetCurrentLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FieldOperationsDashboardService
{
    public const ROUTE_SLA_HOURS = 24;

    private const TERMINAL_EXECUTION_STATUSES = ['delivered', 'completed', 'failed', 'cancelled', 'returned'];

    private const IN_TRANSIT_STATUSES = ['assigned', 'accepted', 'picked_up', 'out_for_delivery'];

    private const FAILED_RETURNED_STATUSES = ['failed', 'returned'];

    private const TERMINAL_ORDER_STATUSES = ['delivered', 'cancelled', 'refunded', 'failed', 'returned'];

    public function __construct(private readonly FleetLocationService $locations) {}

    /**
     * @param  list<int>  $allowedStoreIds
     * @return array<string,mixed>
     */
    public function build(
        array $allowedStoreIds,
        ?int $storeId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $storeIds = array_values(array_unique(array_map('intval', $allowedStoreIds)));
        if ($storeId !== null) {
            $storeIds = in_array($storeId, $storeIds, true) ? [$storeId] : [];
        }

        [$fromUtc, $toUtc, $fromLocal, $toLocal] = $this->bounds($fromDate, $toDate);

        if ($storeIds === []) {
            return $this->emptySnapshot($fromLocal, $toLocal, $storeId);
        }

        $vanIds = $this->scopedVanIds($storeIds);
        $orders = $this->orders($storeIds, $fromUtc, $toUtc);
        $assignments = $this->assignments($storeIds, $fromUtc, $toUtc);

        $activeVans = $vanIds === []
            ? 0
            : DB::table('vans')->whereIn('id', $vanIds)->where('status', 'active')->count();
        $activeDrivers = $vanIds === []
            ? 0
            : DB::table('van_assignments')
                ->whereIn('van_id', $vanIds)
                ->where('status', 'active')
                ->whereNotNull('driver_id')
                ->distinct()
                ->count('driver_id');
        $activeAssignedVans = $vanIds === []
            ? 0
            : DB::table('van_assignments')
                ->whereIn('van_id', $vanIds)
                ->where('status', 'active')
                ->distinct()
                ->count('van_id');

        $assignedDeliveries = (clone $assignments)->count('order_van_assignments.id');
        $inTransit = (clone $assignments)
            ->whereIn('order_van_execution_states.status', self::IN_TRANSIT_STATUSES)
            ->count('order_van_assignments.id');
        $failedReturned = (clone $assignments)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('order_van_execution_states.status', self::FAILED_RETURNED_STATUSES)
                    ->orWhereIn('orders.status', self::FAILED_RETURNED_STATUSES);
            })
            ->count('order_van_assignments.id');

        $delayCutoff = CarbonImmutable::now('UTC')->subHours(self::ROUTE_SLA_HOURS);
        $lateDeliveries = (clone $assignments)
            ->where('order_van_assignments.status', 'active')
            ->where('order_van_assignments.assigned_at', '<=', $delayCutoff)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('order_van_execution_states.status')
                    ->orWhereNotIn('order_van_execution_states.status', self::TERMINAL_EXECUTION_STATUSES);
            })
            ->count('order_van_assignments.id');

        $unassignedOrders = (clone $orders)
            ->whereNotIn('orders.status', self::TERMINAL_ORDER_STATUSES)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('order_van_assignments')
                    ->whereColumn('order_van_assignments.order_id', 'orders.id')
                    ->where('order_van_assignments.status', 'active');
            })
            ->count('orders.id');

        $completedRows = (clone $assignments)
            ->whereIn('order_van_execution_states.status', ['delivered', 'completed'])
            ->whereNotNull('order_van_assignments.assigned_at')
            ->whereNotNull('order_van_execution_states.last_transition_at')
            ->get([
                'order_van_assignments.assigned_at',
                'order_van_execution_states.last_transition_at',
            ]);
        $onTimeDeliveries = $completedRows->filter(static function (object $row): bool {
            $assigned = CarbonImmutable::parse((string) $row->assigned_at, 'UTC');
            $completed = CarbonImmutable::parse((string) $row->last_transition_at, 'UTC');

            return $assigned->diffInMinutes($completed) <= self::ROUTE_SLA_HOURS * 60;
        })->count();
        $routeSla = $completedRows->isEmpty()
            ? null
            : round(($onTimeDeliveries / $completedRows->count()) * 100, 1);

        $locationRows = $vanIds === []
            ? collect()
            : FleetCurrentLocation::query()
                ->where('actor_type', 'van')
                ->whereIn('actor_id', $vanIds)
                ->get();
        $locationHealth = ['online' => 0, 'stale' => 0, 'offline' => max(0, $activeVans - $locationRows->count())];
        $staleVans = [];
        foreach ($locationRows as $location) {
            $status = $this->locations->status($location);
            if (array_key_exists($status, $locationHealth)) {
                $locationHealth[$status]++;
            }
            if ($status !== 'online') {
                $staleVans[(int) $location->actor_id] = [
                    'status' => $status,
                    'received_at' => (string) $location->received_at,
                ];
            }
        }

        $pendingRemittancesQuery = DB::table('remittances')
            ->join('collection_accounts', 'collection_accounts.id', '=', 'remittances.collection_account_id')
            ->where('collection_accounts.actor_type', 'van')
            ->whereIn('collection_accounts.store_id', $storeIds)
            ->where('remittances.status', 'pending');
        $pendingRemittances = (clone $pendingRemittancesQuery)->count('remittances.id');

        $statusBreakdown = (clone $assignments)
            ->selectRaw("COALESCE(order_van_execution_states.status, 'assigned') as status, COUNT(*) as deliveries")
            ->groupByRaw("COALESCE(order_van_execution_states.status, 'assigned')")
            ->orderByDesc('deliveries')
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'deliveries' => (int) $row->deliveries,
            ])
            ->values()
            ->all();

        $daily = (clone $assignments)
            ->selectRaw(
                'DATE(order_van_assignments.assigned_at) as day, COUNT(*) as deliveries, '.
                "SUM(CASE WHEN order_van_execution_states.status IN ('delivered','completed') THEN 1 ELSE 0 END) as completed, ".
                "SUM(CASE WHEN order_van_execution_states.status IN ('failed','returned') THEN 1 ELSE 0 END) as failed"
            )
            ->whereNotNull('order_van_assignments.assigned_at')
            ->groupByRaw('DATE(order_van_assignments.assigned_at)')
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'deliveries' => (int) $row->deliveries,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
            ])
            ->values()
            ->all();

        $vanWorkload = (clone $assignments)
            ->selectRaw(
                'vans.id as van_id, vans.code as van, COUNT(*) as deliveries, '.
                "SUM(CASE WHEN order_van_execution_states.status IN ('delivered','completed') THEN 1 ELSE 0 END) as completed, ".
                "SUM(CASE WHEN order_van_execution_states.status IN ('failed','returned') THEN 1 ELSE 0 END) as failed"
            )
            ->groupBy('vans.id', 'vans.code')
            ->orderByDesc('deliveries')
            ->limit(12)
            ->get()
            ->map(static fn (object $row): array => [
                'van_id' => (int) $row->van_id,
                'van' => (string) $row->van,
                'deliveries' => (int) $row->deliveries,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
            ])
            ->values()
            ->all();

        $failedReasons = (clone $assignments)
            ->whereIn('order_van_execution_states.status', self::FAILED_RETURNED_STATUSES)
            ->selectRaw("COALESCE(NULLIF(TRIM(order_van_execution_states.failure_reason_code), ''), 'unspecified') as reason, COUNT(*) as failures")
            ->groupByRaw("COALESCE(NULLIF(TRIM(order_van_execution_states.failure_reason_code), ''), 'unspecified')")
            ->orderByDesc('failures')
            ->limit(10)
            ->get()
            ->map(static fn (object $row): array => [
                'reason' => (string) $row->reason,
                'failures' => (int) $row->failures,
            ])
            ->values()
            ->all();

        $territoryProductivity = $vanIds === []
            ? []
            : DB::table('van_assignments as territory_assignments')
                ->join('vans', 'vans.id', '=', 'territory_assignments.van_id')
                ->leftJoin('order_van_assignments as territory_orders', function ($join): void {
                    $join->on('territory_orders.van_id', '=', 'vans.id')
                        ->where('territory_orders.status', 'active');
                })
                ->leftJoin('orders as territory_order_records', function ($join) use ($storeIds, $fromUtc, $toUtc): void {
                    $join->on('territory_order_records.id', '=', 'territory_orders.order_id')
                        ->where('territory_order_records.channel', '=', 'b2b')
                        ->whereIn('territory_order_records.store_id', $storeIds)
                        ->whereBetween('territory_order_records.created_at', [$fromUtc, $toUtc]);
                })
                ->whereIn('territory_assignments.van_id', $vanIds)
                ->where('territory_assignments.status', 'active')
                ->whereNotNull('territory_assignments.territory_key')
                ->selectRaw('territory_assignments.territory_key as territory, COUNT(DISTINCT territory_assignments.van_id) as vans, COUNT(DISTINCT territory_order_records.id) as deliveries')
                ->groupBy('territory_assignments.territory_key')
                ->orderByDesc('deliveries')
                ->limit(12)
                ->get()
                ->map(static fn (object $row): array => [
                    'territory' => (string) $row->territory,
                    'vans' => (int) $row->vans,
                    'deliveries' => (int) $row->deliveries,
                ])
                ->values()
                ->all();

        $remittanceTrend = DB::table('remittances')
            ->join('collection_accounts', 'collection_accounts.id', '=', 'remittances.collection_account_id')
            ->where('collection_accounts.actor_type', 'van')
            ->whereIn('collection_accounts.store_id', $storeIds)
            ->whereBetween('remittances.created_at', [$fromUtc, $toUtc])
            ->selectRaw('DATE(remittances.created_at) as day, remittances.status, COUNT(*) as remittances')
            ->groupByRaw('DATE(remittances.created_at), remittances.status')
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'status' => (string) $row->status,
                'remittances' => (int) $row->remittances,
            ])
            ->values()
            ->all();

        $exceptions = $this->exceptionQueue(
            $orders,
            $assignments,
            $pendingRemittancesQuery,
            $staleVans,
            $vanIds,
            $delayCutoff,
        );

        return [
            'period' => [
                'from' => $fromLocal->toDateString(),
                'to' => $toLocal->toDateString(),
                'timezone' => 'Asia/Kuwait',
                'route_sla_hours' => self::ROUTE_SLA_HOURS,
                'store_id' => $storeId,
            ],
            'kpis' => [
                'active_vans' => $activeVans,
                'active_drivers' => $activeDrivers,
                'assigned_deliveries' => $assignedDeliveries,
                'in_transit_deliveries' => $inTransit,
                'late_deliveries' => $lateDeliveries,
                'unassigned_orders' => $unassignedOrders,
                'failed_returned_deliveries' => $failedReturned,
                'route_sla_percent' => $routeSla,
                'fleet_utilization_percent' => $activeVans === 0 ? null : round(($activeAssignedVans / $activeVans) * 100, 1),
                'pending_remittances' => $pendingRemittances,
                'stale_location_signals' => $locationHealth['stale'] + $locationHealth['offline'],
            ],
            'location_health' => $locationHealth,
            'daily' => $daily,
            'status_breakdown' => $statusBreakdown,
            'van_workload' => $vanWorkload,
            'territory_productivity' => $territoryProductivity,
            'failed_reasons' => $failedReasons,
            'remittance_trend' => $remittanceTrend,
            'exceptions' => $exceptions,
            'generated_at' => CarbonImmutable::now('Asia/Kuwait')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<int>
     */
    private function scopedVanIds(array $storeIds): array
    {
        return DB::table('vans')
            ->leftJoin('warehouses as home_warehouses', 'home_warehouses.id', '=', 'vans.home_warehouse_id')
            ->where(function (Builder $query) use ($storeIds): void {
                $query
                    ->whereIn('home_warehouses.store_id', $storeIds)
                    ->orWhereExists(function (Builder $assignment) use ($storeIds): void {
                        $assignment->selectRaw('1')
                            ->from('van_assignments')
                            ->join('warehouses as assignment_warehouses', 'assignment_warehouses.id', '=', 'van_assignments.warehouse_id')
                            ->whereColumn('van_assignments.van_id', 'vans.id')
                            ->whereIn('assignment_warehouses.store_id', $storeIds);
                    })
                    ->orWhereExists(function (Builder $delivery) use ($storeIds): void {
                        $delivery->selectRaw('1')
                            ->from('order_van_assignments')
                            ->join('orders as scoped_orders', 'scoped_orders.id', '=', 'order_van_assignments.order_id')
                            ->whereColumn('order_van_assignments.van_id', 'vans.id')
                            ->where('scoped_orders.channel', 'b2b')
                            ->whereIn('scoped_orders.store_id', $storeIds);
                    });
            })
            ->pluck('vans.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<int> $storeIds */
    private function orders(array $storeIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Builder
    {
        return DB::table('orders')
            ->where('orders.channel', 'b2b')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc]);
    }

    /** @param list<int> $storeIds */
    private function assignments(array $storeIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Builder
    {
        return DB::table('order_van_assignments')
            ->join('orders', 'orders.id', '=', 'order_van_assignments.order_id')
            ->join('vans', 'vans.id', '=', 'order_van_assignments.van_id')
            ->leftJoin(
                'order_van_execution_states',
                'order_van_execution_states.order_van_assignment_id',
                '=',
                'order_van_assignments.id',
            )
            ->where('orders.channel', 'b2b')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc]);
    }

    /**
     * @param  array<int,array{status:string,received_at:string}>  $staleVans
     * @param  list<int>  $vanIds
     * @return list<array<string,mixed>>
     */
    private function exceptionQueue(
        Builder $orders,
        Builder $assignments,
        Builder $pendingRemittances,
        array $staleVans,
        array $vanIds,
        CarbonImmutable $delayCutoff,
    ): array {
        $rows = collect();

        (clone $orders)
            ->whereNotIn('orders.status', self::TERMINAL_ORDER_STATUSES)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('order_van_assignments')
                    ->whereColumn('order_van_assignments.order_id', 'orders.id')
                    ->where('order_van_assignments.status', 'active');
            })
            ->latest('orders.created_at')
            ->limit(10)
            ->get(['orders.id', 'orders.order_number', 'orders.created_at'])
            ->each(function (object $row) use ($rows): void {
                $rows->push([
                    'type' => 'unassigned_order',
                    'reference_id' => (int) $row->id,
                    'reference' => (string) $row->order_number,
                    'van_id' => null,
                    'detail' => null,
                    'occurred_at' => (string) $row->created_at,
                ]);
            });

        (clone $assignments)
            ->where('order_van_assignments.status', 'active')
            ->where('order_van_assignments.assigned_at', '<=', $delayCutoff)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('order_van_execution_states.status')
                    ->orWhereNotIn('order_van_execution_states.status', self::TERMINAL_EXECUTION_STATUSES);
            })
            ->latest('order_van_assignments.assigned_at')
            ->limit(10)
            ->get([
                'orders.id',
                'orders.order_number',
                'vans.id as van_id',
                'vans.code as van',
                'order_van_assignments.assigned_at',
            ])
            ->each(function (object $row) use ($rows): void {
                $rows->push([
                    'type' => 'late_delivery',
                    'reference_id' => (int) $row->id,
                    'reference' => (string) $row->order_number,
                    'van_id' => (int) $row->van_id,
                    'detail' => (string) $row->van,
                    'occurred_at' => (string) $row->assigned_at,
                ]);
            });

        (clone $assignments)
            ->whereIn('order_van_execution_states.status', self::FAILED_RETURNED_STATUSES)
            ->latest('order_van_execution_states.last_transition_at')
            ->limit(10)
            ->get([
                'orders.id',
                'orders.order_number',
                'vans.id as van_id',
                'vans.code as van',
                'order_van_execution_states.failure_reason_code',
                'order_van_execution_states.last_transition_at',
            ])
            ->each(function (object $row) use ($rows): void {
                $rows->push([
                    'type' => 'failed_delivery',
                    'reference_id' => (int) $row->id,
                    'reference' => (string) $row->order_number,
                    'van_id' => (int) $row->van_id,
                    'detail' => (string) ($row->failure_reason_code ?: $row->van),
                    'occurred_at' => (string) $row->last_transition_at,
                ]);
            });

        if ($vanIds !== [] && $staleVans !== []) {
            DB::table('vans')
                ->whereIn('id', array_keys($staleVans))
                ->get(['id', 'code'])
                ->each(function (object $van) use ($rows, $staleVans): void {
                    $health = $staleVans[(int) $van->id];
                    $rows->push([
                        'type' => 'stale_location',
                        'reference_id' => (int) $van->id,
                        'reference' => (string) $van->code,
                        'van_id' => (int) $van->id,
                        'detail' => $health['status'],
                        'occurred_at' => $health['received_at'],
                    ]);
                });
        }

        (clone $pendingRemittances)
            ->latest('remittances.created_at')
            ->limit(10)
            ->get(['remittances.id', 'remittances.created_at'])
            ->each(function (object $row) use ($rows): void {
                $rows->push([
                    'type' => 'pending_remittance',
                    'reference_id' => (int) $row->id,
                    'reference' => '#'.(int) $row->id,
                    'van_id' => null,
                    'detail' => null,
                    'occurred_at' => (string) $row->created_at,
                ]);
            });

        return $rows
            ->sortByDesc(static fn (array $row): string => (string) $row['occurred_at'])
            ->take(25)
            ->values()
            ->all();
    }

    /** @return array{CarbonImmutable,CarbonImmutable,CarbonImmutable,CarbonImmutable} */
    private function bounds(?string $fromDate, ?string $toDate): array
    {
        $now = CarbonImmutable::now('Asia/Kuwait');
        $from = $fromDate === null || trim($fromDate) === ''
            ? $now->subDays(29)->startOfDay()
            : CarbonImmutable::parse($fromDate, 'Asia/Kuwait')->startOfDay();
        $to = $toDate === null || trim($toDate) === ''
            ? $now->endOfDay()
            : CarbonImmutable::parse($toDate, 'Asia/Kuwait')->endOfDay();

        if ($to->lessThan($from)) {
            $to = $from->endOfDay();
        }

        return [$from->utc(), $to->utc(), $from, $to];
    }

    private function emptySnapshot(CarbonImmutable $from, CarbonImmutable $to, ?int $storeId): array
    {
        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'timezone' => 'Asia/Kuwait',
                'route_sla_hours' => self::ROUTE_SLA_HOURS,
                'store_id' => $storeId,
            ],
            'kpis' => [
                'active_vans' => 0,
                'active_drivers' => 0,
                'assigned_deliveries' => 0,
                'in_transit_deliveries' => 0,
                'late_deliveries' => 0,
                'unassigned_orders' => 0,
                'failed_returned_deliveries' => 0,
                'route_sla_percent' => null,
                'fleet_utilization_percent' => null,
                'pending_remittances' => 0,
                'stale_location_signals' => 0,
            ],
            'location_health' => ['online' => 0, 'stale' => 0, 'offline' => 0],
            'daily' => [],
            'status_breakdown' => [],
            'van_workload' => [],
            'territory_productivity' => [],
            'failed_reasons' => [],
            'remittance_trend' => [],
            'exceptions' => [],
            'generated_at' => CarbonImmutable::now('Asia/Kuwait')->format('Y-m-d H:i'),
        ];
    }
}
