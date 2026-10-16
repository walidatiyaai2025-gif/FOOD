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
     *   summary:array{total:int,overdue:int,attention:int},
     *   activity:Collection<int,array<string,mixed>>
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

        $summary = [
            'total' => $items->count(),
            'overdue' => $items->where('state', 'overdue')->count(),
            'attention' => $items->where('state', 'attention')->count(),
        ];

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
            'activity' => $activity,
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
            ->get(['id', 'public_id', 'store_id', 'status', 'primary_agent_id', 'waiting_since', 'updated_at'])
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
                $this->date($row->waiting_since),
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
            'updated_at' => $updatedAt,
            'due_at' => $dueAt,
            'deep_link' => $deepLink,
        ];
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
