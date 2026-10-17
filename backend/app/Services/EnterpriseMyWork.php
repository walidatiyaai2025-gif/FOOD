<?php

namespace App\Services;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnterpriseMyWork
{
    public const PAGE_SIZE = 25;

    private const MODULE_LIMIT = 50;

    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @return array{
     *   items:LengthAwarePaginator,
     *   summary:array{total:int,assigned_to_me:int,due_today:int,overdue:int,attention:int,escalated:int,delegated:int,completed_period:int},
     *   analytics:array<string,mixed>,
     *   activity:Collection<int,array<string,mixed>>,
     *   filter_options:array<string,mixed>,
     *   export_rows:Collection<int,array<string,mixed>>
     * }
     */
    public function page(
        User $actor,
        string $search,
        string $module,
        string $state,
        string $owner,
        int $ageDays,
        int $page,
        string $path,
        array $filters = [],
    ): array {
        $search = trim(Str::limit($search, 100, ''));
        $items = collect();

        if ($module === 'all' || $module === 'procurement') {
            $items = $items->concat($this->procurement($actor));
        }
        if ($module === 'all' || $module === 'wms') {
            $items = $items->concat($this->wms($actor));
        }
        if ($module === 'all' || $module === 'finance') {
            $items = $items->concat($this->finance($actor));
        }
        if ($module === 'all' || $module === 'treasury') {
            $items = $items->concat($this->treasury($actor));
        }
        if ($module === 'all' || $module === 'customer_service') {
            $items = $items->concat($this->customerService($actor));
        }

        $items = $this->attachDelegationContext($actor, $items);
        $filterOptions = $this->filterOptions($items);

        $items = $items
            ->when($search !== '', function (Collection $rows) use ($search): Collection {
                $needle = Str::lower($search);

                return $rows->filter(static function (array $item) use ($needle): bool {
                    return Str::contains(Str::lower(implode(' ', [
                        (string) ($item['reference'] ?? ''),
                        (string) ($item['title'] ?? ''),
                        (string) ($item['status'] ?? ''),
                        (string) ($item['module'] ?? ''),
                    ])), $needle);
                });
            })
            ->when($state !== 'all', fn (Collection $rows): Collection => $rows->where('state', $state))
            ->when($owner === 'me', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => (int) ($item['owner_id'] ?? 0) === (int) $actor->getKey(),
            ))
            ->when($ageDays > 0, fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => $this->ageDays($item['updated_at'] ?? null) >= $ageDays,
            ))
            ->when(($filters['kind'] ?? 'all') !== 'all', fn (Collection $rows): Collection => $rows->where('kind', (string) $filters['kind']))
            ->when(($filters['status'] ?? 'all') !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => Str::lower((string) ($item['status'] ?? '')) === Str::lower((string) $filters['status']),
            ))
            ->when(($filters['priority'] ?? 'all') !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => (string) ($item['priority'] ?? 'unconfigured') === (string) $filters['priority'],
            ))
            ->when(($filters['due'] ?? 'all') !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => $this->dueState($item['due_at'] ?? null) === (string) $filters['due'],
            ))
            ->when(isset($filters['scope']) && $filters['scope'] !== '' && $filters['scope'] !== 'all', fn (Collection $rows): Collection => $rows->where('scope_key', (string) $filters['scope']))
            ->when(isset($filters['requester']) && $filters['requester'] !== '' && $filters['requester'] !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => (int) ($item['requester_id'] ?? 0) === $this->filterUserId($filters['requester'], $actor),
            ))
            ->when(isset($filters['assignee']) && $filters['assignee'] !== '' && $filters['assignee'] !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => (int) ($item['effective_owner_id'] ?? $item['owner_id'] ?? 0) === $this->filterUserId($filters['assignee'], $actor),
            ))
            ->when(isset($filters['delegate']) && $filters['delegate'] !== '' && $filters['delegate'] !== 'all', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => (int) ($item['delegated_to_id'] ?? 0) === $this->filterUserId($filters['delegate'], $actor),
            ))
            ->when(isset($filters['from']) && $filters['from'] !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => ($item['updated_at'] ?? null) instanceof Carbon
                    && $item['updated_at']->greaterThanOrEqualTo(Carbon::parse((string) $filters['from'])->startOfDay()),
            ))
            ->when(isset($filters['to']) && $filters['to'] !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $item): bool => ($item['updated_at'] ?? null) instanceof Carbon
                    && $item['updated_at']->lessThanOrEqualTo(Carbon::parse((string) $filters['to'])->endOfDay()),
            ))
            ->sort(static function (array $left, array $right): int {
                $priority = ['overdue' => 0, 'attention' => 1, 'pending' => 2];
                $stateCompare = ($priority[$left['state']] ?? 9) <=> ($priority[$right['state']] ?? 9);
                if ($stateCompare !== 0) {
                    return $stateCompare;
                }

                $leftTime = $left['updated_at'] instanceof Carbon ? $left['updated_at']->getTimestamp() : 0;
                $rightTime = $right['updated_at'] instanceof Carbon ? $right['updated_at']->getTimestamp() : 0;

                return $rightTime <=> $leftTime ?: strcmp((string) $left['key'], (string) $right['key']);
            })
            ->values();

        $completion = $this->completionMetrics($actor);
        $summary = [
            'total' => $items->count(),
            'assigned_to_me' => $items->filter(
                fn (array $item): bool => (int) ($item['effective_owner_id'] ?? $item['owner_id'] ?? 0) === (int) $actor->getKey(),
            )->count(),
            'due_today' => $items->filter(fn (array $item): bool => $this->dueState($item['due_at'] ?? null) === 'today')->count(),
            'overdue' => $items->filter(fn (array $item): bool => $this->dueState($item['due_at'] ?? null) === 'overdue')->count(),
            'attention' => $items->where('state', 'attention')->count(),
            'escalated' => $items->filter(fn (array $item): bool => Str::lower((string) ($item['status'] ?? '')) === 'escalated')->count(),
            'delegated' => $items->filter(
                fn (array $item): bool => ($item['delegated_by_id'] ?? null) !== null || ($item['delegated_to_id'] ?? null) !== null,
            )->count(),
            'completed_period' => $completion['total'],
        ];

        $analytics = $this->analytics($items, $completion);

        $activity = $items
            ->sortByDesc(fn (array $item) => $item['updated_at'] instanceof Carbon
                ? $item['updated_at']->getTimestamp()
                : 0)
            ->take(8)
            ->values();

        $page = max(1, $page);
        $paginator = new LengthAwarePaginator(
            $items->slice(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)->values(),
            $items->count(),
            self::PAGE_SIZE,
            $page,
            ['path' => $path, 'pageName' => 'work_page'],
        );

        foreach ([
            'q' => $search,
            'module' => $module !== 'all' ? $module : null,
            'work_state' => $state !== 'all' ? $state : null,
            'owner' => $owner !== 'all' ? $owner : null,
            'age' => $ageDays > 0 ? $ageDays : null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $paginator->appends($key, $value);
            }
        }

        return [
            'items' => $paginator,
            'summary' => $summary,
            'analytics' => $analytics,
            'activity' => $activity,
            'filter_options' => $filterOptions,
            'export_rows' => $items->take(500)->values(),
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function procurement(User $actor): Collection
    {
        if (! $this->modules->isEnabled('procurement')) {
            return collect();
        }

        $storeIds = $this->scope->allowedStoreIds($actor, 'procurement.purchase_requests.view');
        if ($storeIds === []) {
            return collect();
        }

        return DB::table('enterprise_purchase_requests')
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', ['draft', 'submitted', 'approved'])
            ->orderByDesc('updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get(['id', 'request_number', 'store_id', 'status', 'needed_by', 'created_by', 'updated_at'])
            ->map(function (object $row): array {
                $due = $this->date($row->needed_by);
                $state = $due?->isPast() ? 'overdue' : ((string) $row->status === 'approved' ? 'attention' : 'pending');

                return $this->item(
                    'procurement',
                    'purchase_request',
                    (int) $row->id,
                    (string) $row->request_number,
                    (string) $row->status,
                    $state,
                    (int) ($row->created_by ?? 0),
                    $this->date($row->updated_at),
                    '/admin/erp/procurement?tab=requests&store_id='.(int) $row->store_id.'&request='.(int) $row->id,
                    $due,
                    null,
                    'store',
                    (int) $row->store_id,
                    (int) ($row->created_by ?? 0),
                    'procurement.purchase_requests.view',
                );
            });
    }

    /** @return Collection<int,array<string,mixed>> */
    private function wms(User $actor): Collection
    {
        if (! $this->modules->isEnabled('inventory_wms')) {
            return collect();
        }

        $storeIds = $this->scope->allowedStoreIds($actor, 'inventory.view');
        if ($storeIds === []) {
            return collect();
        }

        $warehouseIds = DB::table('warehouses')
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->pluck('id');
        if ($warehouseIds->isEmpty()) {
            return collect();
        }

        $transfers = DB::table('enterprise_warehouse_transfers as transfer')
            ->join('warehouses as source', 'source.id', '=', 'transfer.source_warehouse_id')
            ->join('warehouses as target', 'target.id', '=', 'transfer.target_warehouse_id')
            ->whereIn('transfer.source_warehouse_id', $warehouseIds)
            ->whereIn('transfer.status', ['draft', 'dispatched'])
            ->orderByDesc('transfer.updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get([
                'transfer.id',
                'transfer.status',
                'transfer.created_by',
                'transfer.updated_at',
                'transfer.source_warehouse_id',
                'source.store_id',
                'source.code as source_code',
                'source.name as source_name',
                'target.code as target_code',
                'target.name as target_name',
            ])
            ->map(fn (object $row): array => $this->item(
                'wms',
                'warehouse_transfer',
                (int) $row->id,
                trim((string) ($row->source_code ?: $row->source_name)).' → '.trim((string) ($row->target_code ?: $row->target_name)),
                (string) $row->status,
                (string) $row->status === 'dispatched' ? 'attention' : 'pending',
                (int) ($row->created_by ?? 0),
                $this->date($row->updated_at),
                '/admin/erp/wms?tab=transfers&store_id='.(int) $row->store_id.'&warehouse_id='.(int) $row->source_warehouse_id,
                null,
                null,
                'store',
                (int) $row->store_id,
                (int) ($row->created_by ?? 0),
                'inventory.view',
            ));

        $counts = DB::table('enterprise_inventory_counts as count')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'count.warehouse_id')
            ->whereIn('count.warehouse_id', $warehouseIds)
            ->where('count.status', 'open')
            ->orderByDesc('count.updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get([
                'count.id',
                'count.status',
                'count.created_by',
                'count.updated_at',
                'count.warehouse_id',
                'warehouse.store_id',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'count.started_at',
            ])
            ->map(fn (object $row): array => $this->item(
                'wms',
                'inventory_count',
                (int) $row->id,
                trim((string) ($row->warehouse_code ?: $row->warehouse_name)).' · '.($this->date($row->started_at)?->format('Y-m-d H:i') ?? ''),
                (string) $row->status,
                'pending',
                (int) ($row->created_by ?? 0),
                $this->date($row->updated_at),
                '/admin/erp/wms?tab=counts&store_id='.(int) $row->store_id.'&warehouse_id='.(int) $row->warehouse_id.'&count_id='.(int) $row->id,
                null,
                null,
                'store',
                (int) $row->store_id,
                (int) ($row->created_by ?? 0),
                'inventory.view',
            ));

        return $transfers->concat($counts);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function finance(User $actor): Collection
    {
        if (! $this->modules->isEnabled('finance') || ! ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('finance.view'))) {
            return collect();
        }

        $query = DB::table('enterprise_finance_journals')
            ->where('status', 'draft');

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $query->where('requested_by', $actor->getKey());
        }

        return $query
            ->orderByDesc('updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get(['id', 'journal_number', 'status', 'requested_by', 'updated_at'])
            ->map(fn (object $row): array => $this->item(
                'finance',
                'journal',
                (int) $row->id,
                (string) $row->journal_number,
                (string) $row->status,
                'pending',
                (int) ($row->requested_by ?? 0),
                $this->date($row->updated_at),
                '/admin/erp/finance?tab=journals',
                null,
                null,
                'platform',
                null,
                (int) ($row->requested_by ?? 0),
                'finance.view',
            ));
    }

    /** @return Collection<int,array<string,mixed>> */
    private function treasury(User $actor): Collection
    {
        if (! $this->modules->isEnabled('treasury')) {
            return collect();
        }

        $storeIds = $this->scope->allowedStoreIds($actor, 'finance.view');
        if ($storeIds === []) {
            return collect();
        }

        $reconciliations = DB::table('enterprise_treasury_reconciliations')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'draft')
            ->orderByDesc('updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get(['id', 'reconciliation_number', 'store_id', 'status', 'period_to', 'requested_by', 'updated_at'])
            ->map(function (object $row): array {
                $due = $this->date($row->period_to);

                return $this->item(
                    'treasury',
                    'reconciliation',
                    (int) $row->id,
                    (string) $row->reconciliation_number,
                    (string) $row->status,
                    $due?->isPast() ? 'overdue' : 'pending',
                    (int) ($row->requested_by ?? 0),
                    $this->date($row->updated_at),
                    '/admin/erp/treasury?tab=reconciliation&store_id='.(int) $row->store_id,
                    $due,
                    null,
                    'store',
                    (int) $row->store_id,
                    (int) ($row->requested_by ?? 0),
                    'finance.view',
                );
            });

        $closings = DB::table('enterprise_treasury_cash_closings')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'draft')
            ->orderByDesc('updated_at')
            ->limit(self::MODULE_LIMIT)
            ->get(['id', 'closing_number', 'store_id', 'status', 'business_date', 'requested_by', 'updated_at'])
            ->map(function (object $row): array {
                $due = $this->date($row->business_date);

                return $this->item(
                    'treasury',
                    'cash_closing',
                    (int) $row->id,
                    (string) $row->closing_number,
                    (string) $row->status,
                    $due?->isPast() ? 'overdue' : 'pending',
                    (int) ($row->requested_by ?? 0),
                    $this->date($row->updated_at),
                    '/admin/erp/treasury?tab=cash_closing&store_id='.(int) $row->store_id,
                    $due,
                    null,
                    'store',
                    (int) $row->store_id,
                    (int) ($row->requested_by ?? 0),
                    'finance.view',
                );
            });

        return $reconciliations->concat($closings);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function customerService(User $actor): Collection
    {
        $storeIds = $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.view');
        if ($storeIds === []) {
            return collect();
        }

        return DB::table('whats_app_conversations')
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', ['WAITING_FOR_AGENT', 'OPEN', 'WAITING_FOR_CUSTOMER'])
            ->orderByDesc('priority')
            ->orderBy('waiting_since')
            ->orderBy('id')
            ->limit(self::MODULE_LIMIT)
            ->get(['id', 'public_id', 'store_id', 'status', 'primary_agent_id', 'priority', 'waiting_since', 'updated_at'])
            ->map(fn (object $row): array => $this->item(
                'customer_service',
                'conversation',
                (int) $row->id,
                (string) $row->public_id,
                (string) $row->status,
                (string) $row->status === 'WAITING_FOR_AGENT' ? 'attention' : 'pending',
                (int) ($row->primary_agent_id ?? 0),
                $this->date($row->updated_at),
                route('admin.whatsapp-inbox.index', ['conversation' => (int) $row->id], false),
                null,
                ((int) $row->priority) > 0 ? 'high' : 'normal',
                'store',
                (int) $row->store_id,
                null,
                'whatsapp.conversations.view',
            ));
    }

    /** @return array<string,mixed> */
    private function item(
        string $module,
        string $kind,
        int $id,
        string $reference,
        string $status,
        string $state,
        int $ownerId,
        ?Carbon $updatedAt,
        string $deepLink,
        ?Carbon $dueAt = null,
        ?string $priority = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
        ?int $requesterId = null,
        ?string $permissionCode = null,
    ): array {
        return [
            'key' => $module.':'.$kind.':'.$id,
            'module' => $module,
            'kind' => $kind,
            'title' => __('enterprise_my_work.kinds.'.$kind),
            'reference' => $reference,
            'status' => $status,
            'state' => $state,
            'owner_id' => $ownerId,
            'effective_owner_id' => $ownerId,
            'requester_id' => $requesterId ?? ($ownerId > 0 ? $ownerId : null),
            'priority' => $priority,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_key' => $scopeType === null ? 'global' : $scopeType.':'.($scopeId ?? 0),
            'permission_code' => $permissionCode,
            'delegated_by_id' => null,
            'delegated_to_id' => null,
            'owner_name' => null,
            'requester_name' => null,
            'delegated_by_name' => null,
            'delegated_to_name' => null,
            'updated_at' => $updatedAt,
            'due_at' => $dueAt,
            'deep_link' => $deepLink,
        ];
    }

    /** @param Collection<int,array<string,mixed>> $items */
    private function attachDelegationContext(User $actor, Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $delegations = DB::table('enterprise_delegations')
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where(function ($query) use ($actor): void {
                $query->where('delegator_id', $actor->getKey())
                    ->orWhere('delegate_id', $actor->getKey());
            })
            ->get(['delegator_id', 'delegate_id', 'permission_code', 'scope_type', 'scope_id']);

        $enriched = $items->map(function (array $item) use ($delegations, $actor): array {
            $permission = (string) ($item['permission_code'] ?? '');
            $scopeType = $item['scope_type'] ?? null;
            $scopeId = $item['scope_id'] ?? null;

            $delegation = $delegations->first(function (object $row) use ($permission, $scopeType, $scopeId, $item, $actor): bool {
                if ($permission === '' || (string) $row->permission_code !== $permission) {
                    return false;
                }
                if ((string) $row->scope_type !== (string) ($scopeType ?? 'platform')) {
                    return false;
                }
                if (($row->scope_id === null ? null : (int) $row->scope_id) !== ($scopeId === null ? null : (int) $scopeId)) {
                    return false;
                }

                return (int) $row->delegator_id === (int) ($item['owner_id'] ?? 0)
                    || (int) $row->delegate_id === (int) $actor->getKey();
            });

            if ($delegation !== null) {
                $item['delegated_by_id'] = (int) $delegation->delegator_id;
                $item['delegated_to_id'] = (int) $delegation->delegate_id;
                if ((int) ($item['owner_id'] ?? 0) === (int) $delegation->delegator_id) {
                    $item['effective_owner_id'] = (int) $delegation->delegate_id;
                }
            }

            return $item;
        });

        $userIds = $enriched
            ->flatMap(fn (array $item): array => [
                $item['owner_id'] ?? null,
                $item['requester_id'] ?? null,
                $item['delegated_by_id'] ?? null,
                $item['delegated_to_id'] ?? null,
            ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $names = $userIds->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        return $enriched->map(function (array $item) use ($names): array {
            $item['owner_name'] = isset($item['owner_id']) ? $names->get((int) $item['owner_id']) : null;
            $item['requester_name'] = isset($item['requester_id']) ? $names->get((int) $item['requester_id']) : null;
            $item['delegated_by_name'] = isset($item['delegated_by_id']) ? $names->get((int) $item['delegated_by_id']) : null;
            $item['delegated_to_name'] = isset($item['delegated_to_id']) ? $names->get((int) $item['delegated_to_id']) : null;

            return $item;
        });
    }

    /** @param Collection<int,array<string,mixed>> $items */
    private function filterOptions(Collection $items): array
    {
        $users = collect();
        foreach ($items as $item) {
            foreach ([
                $item['owner_id'] ?? null => $item['owner_name'] ?? null,
                $item['requester_id'] ?? null => $item['requester_name'] ?? null,
                $item['delegated_to_id'] ?? null => $item['delegated_to_name'] ?? null,
            ] as $id => $name) {
                if (is_numeric($id) && (int) $id > 0 && is_string($name) && $name !== '') {
                    $users->put((int) $id, $name);
                }
            }
        }

        return [
            'kinds' => $items->pluck('kind')->filter()->unique()->sort()->values(),
            'statuses' => $items->pluck('status')->filter()->map(fn ($value): string => (string) $value)->unique()->sort()->values(),
            'priorities' => $items->map(fn (array $item): string => (string) ($item['priority'] ?? 'unconfigured'))->unique()->sort()->values(),
            'scopes' => $items->pluck('scope_key')->filter()->unique()->sort()->values(),
            'users' => $users,
        ];
    }

    /** @param Collection<int,array<string,mixed>> $items */
    private function analytics(Collection $items, array $completion): array
    {
        $due = ['today' => 0, 'overdue_1_7' => 0, 'overdue_8_30' => 0, 'overdue_31_plus' => 0, 'upcoming' => 0, 'unconfigured' => 0];
        foreach ($items as $item) {
            $date = $this->date($item['due_at'] ?? null);
            if ($date === null) {
                $due['unconfigured']++;

                continue;
            }
            if ($date->isToday()) {
                $due['today']++;

                continue;
            }
            if ($date->isFuture()) {
                $due['upcoming']++;

                continue;
            }
            $days = max(1, $date->diffInDays(now()));
            $due[$days <= 7 ? 'overdue_1_7' : ($days <= 30 ? 'overdue_8_30' : 'overdue_31_plus')]++;
        }

        $configured = $items->filter(fn (array $item): bool => $this->date($item['due_at'] ?? null) !== null);
        $breached = $configured->filter(fn (array $item): bool => $this->dueState($item['due_at'] ?? null) === 'overdue')->count();
        $slaTotal = $configured->count();

        return [
            'by_module' => $items->countBy('module')->sortDesc(),
            'by_type' => $items->countBy('kind')->sortDesc(),
            'due_aging' => $due,
            'sla' => [
                'configured' => $slaTotal,
                'met' => max(0, $slaTotal - $breached),
                'breached' => $breached,
                'attainment' => $slaTotal === 0 ? null : round((($slaTotal - $breached) / $slaTotal) * 100, 1),
            ],
            'completion_trend' => $completion['trend'],
            'approval_turnaround' => $completion['turnaround'],
            'delegation_utilization' => [
                'delegated_items' => $items->filter(
                    fn (array $item): bool => ($item['delegated_by_id'] ?? null) !== null || ($item['delegated_to_id'] ?? null) !== null,
                )->count(),
                'visible_items' => $items->count(),
            ],
        ];
    }

    private function completionMetrics(User $actor): array
    {
        $rows = DB::table('enterprise_workflow_steps as step')
            ->join('enterprise_workflow_requests as request', 'request.id', '=', 'step.workflow_request_id')
            ->where('step.actor_id', $actor->getKey())
            ->whereIn('step.status', ['APPROVED', 'REJECTED'])
            ->whereNotNull('step.decided_at')
            ->where('step.decided_at', '>=', now()->subDays(30))
            ->orderBy('step.decided_at')
            ->get(['step.decided_at', 'request.created_at']);

        $trend = [];
        $durations = [];
        foreach ($rows as $row) {
            $decided = $this->date($row->decided_at);
            $created = $this->date($row->created_at);
            if ($decided === null) {
                continue;
            }
            $day = $decided->format('Y-m-d');
            $trend[$day] = ($trend[$day] ?? 0) + 1;
            if ($created !== null && $decided->greaterThanOrEqualTo($created)) {
                $durations[] = $created->diffInMinutes($decided);
            }
        }

        sort($durations);
        $count = count($durations);
        $average = $count === 0 ? null : round(array_sum($durations) / $count, 1);
        $p90 = $count === 0 ? null : $durations[max(0, (int) ceil($count * 0.9) - 1)];

        return [
            'total' => $rows->count(),
            'trend' => collect($trend),
            'turnaround' => ['samples' => $count, 'average_minutes' => $average, 'p90_minutes' => $p90],
        ];
    }

    private function dueState(mixed $value): string
    {
        $date = $this->date($value);
        if ($date === null) {
            return 'unconfigured';
        }
        if ($date->isToday()) {
            return 'today';
        }

        return $date->isPast() ? 'overdue' : 'upcoming';
    }

    private function filterUserId(mixed $value, User $actor): int
    {
        if ((string) $value === 'me') {
            return (int) $actor->getKey();
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ageDays(mixed $value): int
    {
        $date = $this->date($value);

        return $date?->diffInDays(now()) ?? 0;
    }
}
