<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpCenterSnapshot
{
    /** @var list<string> */
    private const WORK_MODULES = [
        'procurement',
        'wms',
        'finance',
        'treasury',
        'customer_service',
    ];

    /** @var array<string,list<string>> */
    private const ACTIVITY_PREFIXES = [
        'procurement' => ['enterprise.purchase', 'enterprise.supplier', 'procurement.'],
        'wms' => ['enterprise.wms', 'inventory.', 'stock.'],
        'finance' => ['enterprise.finance', 'finance.'],
        'treasury' => ['enterprise.treasury', 'treasury.'],
        'bi' => ['enterprise.report', 'report.'],
        'customer_service' => ['whatsapp.', 'customer.', 'b2b.'],
    ];

    public function __construct(
        private readonly EnterpriseActionCenter $actions,
        private readonly EnterpriseMyWork $myWork,
        private readonly OperationalTenantScope $tenantScope,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $cards
     * @return array{
     *   kpis:list<array<string,mixed>>,
     *   work_by_module:list<array<string,mixed>>,
     *   health_rows:list<array<string,mixed>>,
     *   work_preview:list<array<string,mixed>>,
     *   activity:list<array<string,mixed>>,
     *   generated_at:\Illuminate\Support\Carbon
     * }
     */
    public function build(User $actor, array $cards): array
    {
        $actionCenterUrl = route('admin.action-center.index');
        $approvals = $this->actions->page($actor, '', 'all', 1, $actionCenterUrl);
        $work = $this->myWork->page(
            $actor,
            '',
            'all',
            'all',
            'all',
            0,
            1,
            $actionCenterUrl,
        );

        $biCard = collect($cards)->firstWhere('key', 'bi');
        $biAvailable = is_array($biCard) && ($biCard['status'] ?? null) === 'available';
        $failedReportJobs = $biAvailable ? $this->failedReportJobs($actor) : null;

        $moduleCards = collect($cards)
            ->filter(fn (array $card): bool => isset($card['module_code']) && $card['module_code'] !== null)
            ->values();

        $healthyModules = $moduleCards->where('status', 'available')->count();
        $moduleCount = $moduleCards->count();

        $kpis = [
            [
                'key' => 'pending_approvals',
                'value' => $approvals['actions']->total(),
                'url' => route('admin.action-center.index'),
                'status' => $approvals['actions']->total() > 0 ? 'attention' : 'ok',
            ],
            [
                'key' => 'overdue_work',
                'value' => (int) $work['summary']['overdue'],
                'url' => route('admin.action-center.index', ['work_state' => 'overdue']),
                'status' => (int) $work['summary']['overdue'] > 0 ? 'danger' : 'ok',
            ],
            [
                'key' => 'open_exceptions',
                'value' => (int) $work['summary']['attention'],
                'url' => route('admin.action-center.index', ['work_state' => 'attention']),
                'status' => (int) $work['summary']['attention'] > 0 ? 'attention' : 'ok',
            ],
            [
                'key' => 'failed_report_jobs',
                'value' => $failedReportJobs,
                'url' => $biAvailable && is_array($biCard) ? ($biCard['url'] ?? null) : null,
                'status' => is_int($failedReportJobs) && $failedReportJobs > 0 ? 'danger' : 'ok',
            ],
            [
                'key' => 'module_health',
                'value' => $moduleCount > 0 ? $healthyModules.'/'.$moduleCount : null,
                'url' => '#erp-health-chart',
                'status' => $moduleCount > 0 && $healthyModules < $moduleCount ? 'attention' : 'ok',
            ],
        ];

        return [
            'kpis' => $kpis,
            'work_by_module' => $this->workByModule($actor, $cards, $failedReportJobs),
            'health_rows' => $moduleCards
                ->map(fn (array $card): array => [
                    'key' => $card['key'],
                    'status' => $card['status'],
                    'url' => $card['url'] ?? null,
                    'checked_at' => $card['health_checked_at'] ?? null,
                ])
                ->all(),
            'work_preview' => collect($work['items']->items())
                ->take(6)
                ->map(fn (array $item): array => [
                    'module' => $item['module'],
                    'reference' => $item['reference'],
                    'state' => $item['state'],
                    'due_at' => $item['due_at'],
                    'deep_link' => $item['deep_link'],
                ])
                ->all(),
            'activity' => $this->recentActivity($actor, $cards),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cards
     * @return list<array<string,mixed>>
     */
    private function workByModule(User $actor, array $cards, ?int $failedReportJobs): array
    {
        $rows = [];

        foreach ($cards as $card) {
            $key = (string) ($card['key'] ?? '');
            if (! in_array($key, [...self::WORK_MODULES, 'bi'], true)) {
                continue;
            }

            $available = ($card['status'] ?? null) === 'available';
            if (! $available) {
                $rows[] = [
                    'key' => $key,
                    'total' => null,
                    'exceptions' => null,
                    'url' => null,
                ];

                continue;
            }

            if ($key === 'bi') {
                $rows[] = [
                    'key' => $key,
                    'total' => null,
                    'exceptions' => $failedReportJobs,
                    'url' => $card['url'] ?? null,
                ];

                continue;
            }

            $result = $this->myWork->page(
                $actor,
                '',
                $key,
                'all',
                'all',
                0,
                1,
                route('admin.action-center.index'),
            );

            $rows[] = [
                'key' => $key,
                'total' => (int) $result['summary']['total'],
                'exceptions' => (int) $result['summary']['overdue'] + (int) $result['summary']['attention'],
                'url' => route('admin.action-center.index', ['module' => $key]),
            ];
        }

        return $rows;
    }

    private function failedReportJobs(User $actor): int
    {
        $failedExports = Schema::hasTable('enterprise_report_exports')
            ? (int) DB::table('enterprise_report_exports')
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->getKey()))
                ->where('status', 'failed')
                ->count()
            : 0;

        $failedSchedules = Schema::hasTable('enterprise_scheduled_reports')
            ? (int) DB::table('enterprise_scheduled_reports')
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->getKey()))
                ->whereNotNull('last_error')
                ->count()
            : 0;

        return $failedExports + $failedSchedules;
    }

    /**
     * @param  list<array<string,mixed>>  $cards
     * @return list<array<string,mixed>>
     */
    private function recentActivity(User $actor, array $cards): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $cardMap = collect($cards)
            ->filter(fn (array $card): bool => in_array($card['status'] ?? null, ['available', 'degraded'], true))
            ->keyBy('key');

        if ($cardMap->isEmpty()) {
            return [];
        }

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->orderByDesc('audit_logs.id')
            ->limit(80);

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $storeIds = $this->visibleStoreIds($actor);

            $query->where(function ($scoped) use ($actor, $storeIds): void {
                if ($storeIds !== []) {
                    $scoped->whereIn('audit_logs.store_id', $storeIds)
                        ->orWhere(function ($own) use ($actor): void {
                            $own->whereNull('audit_logs.store_id')
                                ->where('audit_logs.user_id', $actor->getKey());
                        });

                    return;
                }

                $scoped->whereNull('audit_logs.store_id')
                    ->where('audit_logs.user_id', $actor->getKey());
            });
        }

        return $query
            ->get([
                'audit_logs.id',
                'audit_logs.event',
                'audit_logs.created_at',
                'users.name as actor_name',
            ])
            ->map(function (object $row) use ($cardMap): ?array {
                $module = $this->moduleForEvent((string) $row->event);
                if ($module === null || ! $cardMap->has($module)) {
                    return null;
                }

                $card = $cardMap->get($module);

                return [
                    'id' => (int) $row->id,
                    'module' => $module,
                    'actor_name' => $row->actor_name === null ? null : (string) $row->actor_name,
                    'created_at' => $row->created_at,
                    'url' => is_array($card) ? ($card['url'] ?? null) : null,
                ];
            })
            ->filter()
            ->take(8)
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function visibleStoreIds(User $actor): array
    {
        $ids = [];

        foreach ([
            'procurement.purchase_requests.view',
            'inventory.view',
            'finance.view',
            'reports.view',
            'customers.view',
            'whatsapp.conversations.view',
            'b2b.accounts.view',
        ] as $permission) {
            $ids = array_merge($ids, $this->tenantScope->allowedStoreIds($actor, $permission));
        }

        $ids = array_map('intval', array_unique($ids));
        sort($ids);

        return array_values($ids);
    }

    private function moduleForEvent(string $event): ?string
    {
        foreach (self::ACTIVITY_PREFIXES as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($event, $prefix)) {
                    return $module;
                }
            }
        }

        return null;
    }
}
