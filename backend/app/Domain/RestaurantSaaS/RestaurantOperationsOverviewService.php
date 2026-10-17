<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RestaurantOperationsOverviewService
{
    /**
     * @param  array{branch?:string|null,from?:string|null,to?:string|null,channel?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function snapshot(SmartOrderingSite $site, User $actor, array $filters): array
    {
        $storeId = (int) $site->store_id;
        $canOrders = $actor->hasPermission('orders.view', $storeId) || $actor->hasPermission('orders.view');
        $canManage = $actor->hasPermission('smart_ordering.manage', $storeId) || $actor->hasPermission('smart_ordering.manage');

        $branches = DB::table('smart_ordering_branches')
            ->where('site_id', $site->getKey())
            ->orderByDesc('is_default')
            ->orderBy('name_en')
            ->get(['id', 'public_id', 'name_ar', 'name_en', 'is_active', 'accepting_orders']);

        $requestedBranch = trim((string) ($filters['branch'] ?? ''));
        $branch = $requestedBranch === ''
            ? null
            : $branches->first(fn (object $row): bool => (string) $row->public_id === $requestedBranch);
        abort_if($requestedBranch !== '' && $branch === null, 404);

        $branchIds = $branch ? [(int) $branch->id] : $branches->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $to = isset($filters['to']) && $filters['to']
            ? CarbonImmutable::parse((string) $filters['to'], 'Asia/Kuwait')->endOfDay()
            : CarbonImmutable::now('Asia/Kuwait')->endOfDay();
        $from = isset($filters['from']) && $filters['from']
            ? CarbonImmutable::parse((string) $filters['from'], 'Asia/Kuwait')->startOfDay()
            : $to->subDays(6)->startOfDay();

        if ($from->diffInDays($to) > 90) {
            $from = $to->subDays(90)->startOfDay();
        }

        $channel = in_array(($filters['channel'] ?? null), ['dine_in', 'pickup', 'delivery'], true)
            ? (string) $filters['channel']
            : null;

        $base = DB::table('smart_ordering_order_contexts as soc')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
            ->where('b.site_id', $site->getKey())
            ->when($branchIds !== [], fn ($q) => $q->whereIn('soc.branch_id', $branchIds))
            ->when($channel, fn ($q) => $q->where('soc.fulfillment_mode', $channel))
            ->whereBetween('soc.created_at', [$from->utc(), $to->utc()]);

        $ordersCount = $canOrders ? (clone $base)->count() : null;
        $revenue = $canOrders ? (float) (clone $base)->sum('soc.grand_total') : null;
        $aov = $canOrders && $ordersCount > 0 ? $revenue / $ordersCount : null;

        $todayStart = CarbonImmutable::now('Asia/Kuwait')->startOfDay();
        $todayEnd = $todayStart->endOfDay();
        $todayBase = DB::table('smart_ordering_order_contexts as soc')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
            ->where('b.site_id', $site->getKey())
            ->when($branchIds !== [], fn ($q) => $q->whereIn('soc.branch_id', $branchIds))
            ->when($channel, fn ($q) => $q->where('soc.fulfillment_mode', $channel))
            ->whereBetween('soc.created_at', [$todayStart->utc(), $todayEnd->utc()]);

        $ordersToday = $canOrders ? (clone $todayBase)->count() : null;
        $currency = $canOrders
            ? (string) (DB::table('smart_ordering_order_contexts as soc')
                ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
                ->join('orders as o', 'o.id', '=', 'soc.order_id')
                ->where('b.site_id', $site->getKey())
                ->when($branchIds !== [], fn ($q) => $q->whereIn('soc.branch_id', $branchIds))
                ->when($channel, fn ($q) => $q->where('soc.fulfillment_mode', $channel))
                ->orderByDesc('soc.id')
                ->value('o.currency') ?? 'KWD')
            : 'KWD';

        $statusRows = $canOrders
            ? (clone $base)->select('soc.lifecycle_status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('soc.lifecycle_status')->orderBy('soc.lifecycle_status')->get()
            : collect();

        $trendRows = $canOrders
            ? (clone $base)->selectRaw('DATE(soc.created_at) as bucket, COUNT(*) as orders_count, SUM(soc.grand_total) as revenue')
                ->groupByRaw('DATE(soc.created_at)')->orderBy('bucket')->get()
            : collect();

        $reservations = $this->countScoped(
            'smart_ordering_reservations',
            $branchIds,
            fn ($q) => $q->whereIn('status', ['BOOKED', 'CONFIRMED', 'SEATED']),
        );
        $waitlist = $this->countScoped(
            'smart_ordering_waitlist_entries',
            $branchIds,
            fn ($q) => $q->whereIn('status', ['WAITING', 'CONTACTED']),
        );

        $kitchenBacklog = null;
        $kitchenLate = null;
        if (Schema::hasTable('smart_ordering_kitchen_ticket_states')) {
            $ticketQuery = DB::table('smart_ordering_kitchen_ticket_states as kt')
                ->join('smart_ordering_order_contexts as soc', 'soc.id', '=', 'kt.order_context_id')
                ->whereIn('soc.branch_id', $branchIds ?: [-1])
                ->whereIn('kt.status', ['queued', 'preparing']);
            $kitchenBacklog = (clone $ticketQuery)->count();
            $kitchenLate = (clone $ticketQuery)->where('kt.updated_at', '<', now()->subMinutes(20))->count();
        }

        $onShift = null;
        $attendanceExceptions = null;
        if (Schema::hasTable('restaurant_time_clock_sessions')) {
            $onShift = DB::table('restaurant_time_clock_sessions')
                ->whereIn('branch_id', $branchIds ?: [-1])
                ->whereIn('status', ['OPEN', 'ON_BREAK'])
                ->count();

            $attendanceExceptions = DB::table('restaurant_shifts as s')
                ->leftJoin('restaurant_time_clock_sessions as tc', function ($join): void {
                    $join->on('tc.shift_id', '=', 's.id')
                        ->whereIn('tc.status', ['OPEN', 'ON_BREAK', 'CLOSED']);
                })
                ->whereIn('s.branch_id', $branchIds ?: [-1])
                ->where('s.status', 'PUBLISHED')
                ->where('s.starts_at', '<=', now())
                ->where('s.ends_at', '>=', now())
                ->whereNull('tc.id')
                ->count();
        }

        $wasteEvents = null;
        if (Schema::hasTable('smart_ordering_supply_waste_events')) {
            $wasteEvents = DB::table('smart_ordering_supply_waste_events')
                ->where('site_id', $site->getKey())
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
                ->count();
        }

        $reorderDrafts = null;
        if (Schema::hasTable('smart_ordering_supply_reorder_drafts')) {
            $reorderDrafts = DB::table('smart_ordering_supply_reorder_drafts')
                ->where('site_id', $site->getKey())
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->whereNull('converted_at')
                ->count();
        }

        $integrationsActive = null;
        $integrationsDegraded = null;
        $failedSyncs = null;
        if (Schema::hasTable('restaurant_saas_integrations')) {
            $integrationIds = DB::table('restaurant_saas_integrations')
                ->where('site_id', $site->getKey())
                ->when($branch, fn ($q) => $q->where(function ($scoped) use ($branch): void {
                    $scoped->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                }))
                ->pluck('id');
            $integrationsActive = DB::table('restaurant_saas_integrations')
                ->whereIn('id', $integrationIds)
                ->whereIn('status', ['active', 'enabled', 'healthy'])
                ->count();
            $integrationsDegraded = DB::table('restaurant_saas_integrations')
                ->whereIn('id', $integrationIds)
                ->whereIn('status', ['degraded', 'failed', 'error'])
                ->count();

            if (Schema::hasTable('restaurant_saas_integration_events')) {
                $failedSyncs = DB::table('restaurant_saas_integration_events')
                    ->whereIn('integration_id', $integrationIds)
                    ->whereIn('status', ['failed', 'dead_letter', 'error'])
                    ->count();
            }
        }

        $branchHealth = $branches->map(function (object $row): array {
            return [
                'public_id' => (string) $row->public_id,
                'label' => app()->getLocale() === 'ar' && $row->name_ar ? (string) $row->name_ar : (string) $row->name_en,
                'state' => ! $row->is_active ? 'disabled' : ($row->accepting_orders ? 'healthy' : 'paused'),
            ];
        })->all();

        $exceptions = array_values(array_filter([
            $this->exception('kitchen_late', $kitchenLate),
            $this->exception('attendance', $attendanceExceptions),
            $this->exception('waitlist', $waitlist),
            $this->exception('reorder', $reorderDrafts),
            $this->exception('integrations', ($integrationsDegraded ?? 0) + ($failedSyncs ?? 0)),
        ]));

        return [
            'currency' => strtoupper($currency),
            'filters' => [
                'branch' => $branch?->public_id,
                'branch_id' => $branch?->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'channel' => $channel,
            ],
            'branches' => $branches,
            'kpis' => [
                'orders_today' => $ordersToday,
                'orders_period' => $ordersCount,
                'revenue' => $revenue,
                'aov' => $aov,
                'reservations' => $reservations,
                'waitlist' => $waitlist,
                'kitchen_backlog' => $kitchenBacklog,
                'kitchen_late' => $kitchenLate,
                'on_shift' => $onShift,
                'attendance_exceptions' => $attendanceExceptions,
                'waste_events' => $wasteEvents,
                'reorder_drafts' => $reorderDrafts,
                'integrations_active' => $integrationsActive,
                'integrations_degraded' => $integrationsDegraded,
                'failed_syncs' => $failedSyncs,
            ],
            'order_status' => $statusRows->mapWithKeys(fn (object $row): array => [(string) $row->lifecycle_status => (int) $row->aggregate])->all(),
            'trend' => $trendRows->map(fn (object $row): array => [
                'date' => (string) $row->bucket,
                'orders' => (int) $row->orders_count,
                'revenue' => (float) $row->revenue,
            ])->all(),
            'branch_health' => $branchHealth,
            'exceptions' => $exceptions,
            'can_manage' => $canManage,
            'can_orders' => $canOrders,
            'last_refresh' => now()->toIso8601String(),
        ];
    }

    private function countScoped(string $table, array $branchIds, callable $callback): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table)->whereIn('branch_id', $branchIds ?: [-1]);
        $callback($query);

        return $query->count();
    }

    /** @return array{key:string,count:int}|null */
    private function exception(string $key, ?int $count): ?array
    {
        return $count !== null && $count > 0 ? ['key' => $key, 'count' => $count] : null;
    }
}
