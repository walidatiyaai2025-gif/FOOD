<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\EnterpriseBiWorkspaceService;
use App\Enterprise\Reporting\ReportAccessResolver;
use App\Enterprise\Reporting\ReportBuilderExecutor;
use App\Enterprise\Reporting\ReportDefinition;
use App\Enterprise\Reporting\ReportRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class EnterpriseBiController extends Controller
{
    private const REPORT_UI = [
        'orders.summary' => [
            'key' => 'orders',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'daily' => ['date', 'status', 'channel', 'currency'],
                'status' => ['status', 'channel', 'currency'],
                'channel' => ['channel', 'currency'],
            ],
        ],
        'commercial.profitability' => [
            'key' => 'profitability',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'overview' => ['date', 'channel', 'currency', 'status'],
                'product' => ['product_id', 'channel', 'currency'],
                'customer' => ['customer_id', 'channel', 'currency'],
                'channel' => ['channel', 'currency', 'status'],
            ],
        ],
        'finance.ar_exposure' => [
            'key' => 'ar',
            'scope' => EntitlementScope::LEGAL_ENTITY,
            'breakdowns' => [
                'aging' => ['aging_bucket', 'currency'],
                'customer' => ['customer_id', 'aging_bucket', 'currency'],
                'branch' => ['store_id', 'aging_bucket', 'currency'],
            ],
        ],
        'finance.ap_exposure' => [
            'key' => 'ap',
            'scope' => EntitlementScope::LEGAL_ENTITY,
            'breakdowns' => [
                'aging' => ['aging_bucket', 'currency'],
                'supplier' => ['supplier_reference', 'aging_bucket', 'currency'],
                'branch' => ['store_id', 'aging_bucket', 'currency'],
            ],
        ],
        'wms.stock_exposure' => [
            'key' => 'stock',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'status' => ['stock_status', 'aging_bucket'],
                'warehouse' => ['warehouse_id', 'stock_status'],
                'product' => ['product_id', 'stock_status'],
            ],
        ],
        'procurement.open_orders' => [
            'key' => 'procurement_open_orders',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'overview' => ['order_number', 'supplier_reference', 'status', 'needed_by', 'currency'],
            ],
        ],
        'procurement.spend' => [
            'key' => 'procurement_spend',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'supplier' => ['supplier_reference', 'currency'],
            ],
        ],
        'procurement.supplier_performance' => [
            'key' => 'procurement_supplier_performance',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'supplier' => ['supplier_reference'],
            ],
        ],
        'procurement.savings' => [
            'key' => 'procurement_savings',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'overview' => ['rfq_number', 'supplier_reference', 'currency'],
            ],
        ],
        'procurement.contract_expiry' => [
            'key' => 'procurement_contract_expiry',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'overview' => ['contract_number', 'supplier_reference', 'status', 'currency', 'starts_on', 'ends_on'],
            ],
        ],
        'procurement.match_exceptions' => [
            'key' => 'procurement_match_exceptions',
            'scope' => EntitlementScope::STORE,
            'breakdowns' => [
                'overview' => ['invoice_number', 'supplier_invoice_number', 'supplier_reference', 'status', 'currency'],
            ],
        ],
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly OperationalTenantScope $tenantScope,
    ) {}

    public function index(
        Request $request,
        ReportRegistry $registry,
        ReportAccessResolver $access,
        ReportBuilderExecutor $executor,
        EnterpriseBiWorkspaceService $workspace,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('reports.view'), 403);

        $catalog = collect($this->catalog($actor, $registry, $access));
        $selectedCode = trim((string) $request->query('report', ''));
        $selected = $catalog->firstWhere('code', $selectedCode)
            ?? $catalog->firstWhere('available', true)
            ?? $catalog->first();

        $run = $request->boolean('run');
        $result = null;
        $rows = [];
        $runError = null;
        $selection = null;

        if (is_array($selected)) {
            $selection = $this->selection($request, $selected);

            if ($run && $selected['available'] && $selection['scope'] instanceof EntitlementScope) {
                try {
                    $result = $executor->execute(
                        $actor,
                        $selected['code'],
                        [
                            'from' => $selection['from'],
                            'to' => $selection['to'],
                            'page' => $selection['page'],
                            'per_page' => $selection['per_page'],
                            'filters' => $selection['filters'],
                            'dimensions' => $selection['dimensions'],
                            'measures' => $selection['measures'],
                            'group_by' => $selection['dimensions'],
                            'sort' => $selection['sort'],
                        ],
                        $selection['scope'],
                    );

                    $rows = $this->decorateRows((array) ($result['rows'] ?? []));
                } catch (AuthorizationException) {
                    abort(403);
                } catch (ValidationException) {
                    $runError = __('enterprise_bi_ui.states.error');
                }
            }
        }

        return view('admin.enterprise-bi', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'reports_center',
            'catalog' => $catalog,
            'selected' => $selected,
            'selection' => $selection,
            'result' => $result,
            'rows' => $rows,
            'runError' => $runError,
            'overview' => $workspace->overview($actor, $catalog->all(), $request, $selection),
            'canSchedule' => $actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('reports.export'),
            'exports' => $this->recentExports($actor),
            'schedules' => $this->recentSchedules($actor),
            'exceptions' => $this->exceptionSummary($actor),
            'legacyReportsUrl' => Route::has('admin.reports.index') ? route('admin.reports.index') : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function catalog(User $actor, ReportRegistry $registry, ReportAccessResolver $access): array
    {
        return $registry->all()
            ->filter(fn (ReportDefinition $definition): bool => isset(self::REPORT_UI[$definition->code]))
            ->map(function (ReportDefinition $definition) use ($actor, $access): array {
                $ui = self::REPORT_UI[$definition->code];
                $scopeOptions = $this->scopeOptions($actor, $definition, $ui['scope'], $access);

                /** @var array<string, mixed> $catalogItem */
                $catalogItem = [
                    'code' => $definition->code,
                    'key' => $ui['key'],
                    'scope_type' => $ui['scope'],
                    'breakdowns' => $ui['breakdowns'],
                    'definition' => $definition,
                    'scope_options' => $scopeOptions,
                    'available' => $scopeOptions !== [],
                    'title' => __('enterprise_bi_ui.reports.'.$ui['key'].'.title'),
                    'description' => __('enterprise_bi_ui.reports.'.$ui['key'].'.description'),
                ];

                return $catalogItem;
            })
            ->values()
            ->all();
    }

    /** @return list<array{key:string,label:string,scope:EntitlementScope}> */
    private function scopeOptions(
        User $actor,
        ReportDefinition $definition,
        string $scopeType,
        ReportAccessResolver $access,
    ): array {
        $isSuper = $actor->hasRole('SUPER_ADMIN');
        $allowedStoreIds = $isSuper
            ? []
            : $this->tenantScope->allowedStoreIds($actor, 'reports.view');

        if ($scopeType === EntitlementScope::STORE) {
            return DB::table('stores')
                ->where('is_active', true)
                ->when(! $isSuper, fn ($query) => $query->whereIn('id', $allowedStoreIds))
                ->orderBy('name')
                ->limit(250)
                ->get(['id', 'name', 'code'])
                ->map(function (object $row) use ($actor, $definition, $access): ?array {
                    $scope = new EntitlementScope(EntitlementScope::STORE, (int) $row->id);
                    if (! $access->allowsView($actor, $definition, $scope)) {
                        return null;
                    }

                    return [
                        'key' => $scope->key(),
                        'label' => trim((string) $row->name).' · '.trim((string) $row->code),
                        'scope' => $scope,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        $entities = DB::table('enterprise_legal_entities as entity')
            ->where('entity.is_active', true)
            ->when(! $isSuper, function ($query) use ($allowedStoreIds): void {
                $query->whereExists(function ($scoped) use ($allowedStoreIds): void {
                    $scoped->selectRaw('1')
                        ->from('enterprise_business_units as unit')
                        ->join(
                            'enterprise_store_scopes as store_scope',
                            'store_scope.business_unit_id',
                            '=',
                            'unit.id',
                        )
                        ->whereColumn('unit.legal_entity_id', 'entity.id')
                        ->whereIn('store_scope.store_id', $allowedStoreIds);
                });
            })
            ->orderBy('entity.name')
            ->limit(250)
            ->get(['entity.id', 'entity.name', 'entity.code']);

        return $entities
            ->map(function (object $row) use ($actor, $definition, $access): ?array {
                $scope = new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $row->id);
                if (! $access->allowsView($actor, $definition, $scope)) {
                    return null;
                }

                return [
                    'key' => $scope->key(),
                    'label' => trim((string) $row->name).' · '.trim((string) $row->code),
                    'scope' => $scope,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $selected
     * @return array<string,mixed>
     */
    private function selection(Request $request, array $selected): array
    {
        $definition = $selected['definition'];
        $scopeOptions = collect($selected['scope_options']);
        $scopeKey = trim((string) $request->query('scope', ''));
        $scopeOption = $scopeOptions->firstWhere('key', $scopeKey) ?? $scopeOptions->first();

        $breakdown = trim((string) $request->query('breakdown', ''));
        if (! array_key_exists($breakdown, $selected['breakdowns'])) {
            $breakdown = (string) array_key_first($selected['breakdowns']);
        }

        $maxDays = max(1, (int) $definition->maxRangeDays);
        $today = now()->toDateString();
        $defaultFrom = $maxDays === 1
            ? $today
            : now()->subDays(min(6, $maxDays - 1))->toDateString();

        $from = trim((string) $request->query('from', $defaultFrom));
        $to = trim((string) $request->query('to', $today));

        if ($maxDays === 1) {
            $from = $to !== '' ? $to : $today;
            $to = $from;
        }

        $page = max(1, min(10000, (int) $request->query('page', 1)));

        $requestedDimensions = $request->query('dimensions');
        $dimensions = is_array($requestedDimensions)
            ? collect($requestedDimensions)
                ->filter(fn (mixed $value): bool => is_string($value) && in_array($value, $definition->dimensions, true))
                ->unique()
                ->values()
                ->all()
            : $selected['breakdowns'][$breakdown];
        if ($dimensions === []) {
            $dimensions = $selected['breakdowns'][$breakdown];
        }

        $requestedMeasures = $request->query('measures');
        $measures = is_array($requestedMeasures)
            ? collect($requestedMeasures)
                ->filter(fn (mixed $value): bool => is_string($value) && in_array($value, $definition->measures, true))
                ->unique()
                ->values()
                ->all()
            : $definition->measures;
        if ($measures === []) {
            $measures = $definition->measures;
        }

        $filters = [];
        foreach ($definition->allowedFilters as $filter) {
            $value = $request->query('filter_'.$filter);
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $filters[$filter] = mb_substr(trim((string) $value), 0, 120);
        }

        $sort = [];
        $sortField = trim((string) $request->query('sort_field', ''));
        $sortDirection = strtolower(trim((string) $request->query('sort_direction', 'asc')));
        if (in_array($sortField, [...$dimensions, ...$measures], true)
            && in_array($sortDirection, ['asc', 'desc'], true)) {
            $sort[] = ['field' => $sortField, 'direction' => $sortDirection];
        }

        return [
            'report' => (string) $selected['code'],
            'scope_key' => is_array($scopeOption) ? $scopeOption['key'] : null,
            'scope' => is_array($scopeOption) ? $scopeOption['scope'] : null,
            'breakdown' => $breakdown,
            'dimensions' => $dimensions,
            'measures' => $measures,
            'filters' => $filters,
            'sort' => $sort,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => min(50, (int) $definition->maxPageSize),
        ];
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array{cells:array<string,mixed>,drill_url:?string,provenance:bool}>
     */
    private function decorateRows(array $rows): array
    {
        $productIds = $this->ids($rows, 'product_id');
        $customerIds = $this->ids($rows, 'customer_id');
        $storeIds = $this->ids($rows, 'store_id');
        $warehouseIds = $this->ids($rows, 'warehouse_id');

        $products = $this->labelMap('products', $productIds, 'name');
        $customers = $this->labelMap('customers', $customerIds, 'name');
        $stores = $this->labelMap('stores', $storeIds, 'name');
        $warehouses = $this->labelMap('warehouses', $warehouseIds, 'name', 'code');

        return collect($rows)->map(function (array $row) use ($products, $customers, $stores, $warehouses): array {
            $drill = isset($row['drill_down']) && is_array($row['drill_down']) ? $row['drill_down'] : null;
            unset($row['drill_down']);

            foreach ([
                'product_id' => ['product', $products],
                'customer_id' => ['customer', $customers],
                'store_id' => ['branch', $stores],
                'warehouse_id' => ['warehouse', $warehouses],
            ] as $source => [$target, $map]) {
                if (! array_key_exists($source, $row)) {
                    continue;
                }

                $id = (int) $row[$source];
                unset($row[$source]);
                $row[$target] = $map[$id] ?? __('enterprise_bi_ui.values.unavailable_reference');
            }

            if (array_key_exists('supplier_reference', $row)) {
                $row['supplier'] = (string) $row['supplier_reference'];
                unset($row['supplier_reference']);
            }

            $drillUrl = null;
            if (is_array($drill) && isset($drill['route']) && is_string($drill['route']) && Route::has($drill['route'])) {
                $filters = isset($drill['filters']) && is_array($drill['filters']) ? $drill['filters'] : [];
                $filters = array_filter(
                    $filters,
                    static fn (mixed $value): bool => is_scalar($value) && $value !== '',
                );
                $drillUrl = route($drill['route'], $filters);
            }

            return [
                'cells' => $row,
                'drill_url' => $drillUrl,
                'provenance' => $drill !== null,
            ];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows, string $field): array
    {
        return collect($rows)
            ->pluck($field)
            ->filter(fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<int> $ids
     * @return array<int,string>
     */
    private function labelMap(string $table, array $ids, string $nameColumn, ?string $codeColumn = null): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        $columns = ['id', $nameColumn];
        if ($codeColumn !== null) {
            $columns[] = $codeColumn;
        }

        return DB::table($table)
            ->whereIn('id', $ids)
            ->get($columns)
            ->mapWithKeys(function (object $row) use ($nameColumn, $codeColumn): array {
                $label = trim((string) $row->{$nameColumn});
                if ($codeColumn !== null && trim((string) $row->{$codeColumn}) !== '') {
                    $label .= ' · '.trim((string) $row->{$codeColumn});
                }

                return [(int) $row->id => $label];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function recentExports(User $actor): array
    {
        if (! Schema::hasTable('enterprise_report_exports')) {
            return [];
        }

        return DB::table('enterprise_report_exports')
            ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
            ->orderByDesc('id')
            ->limit(12)
            ->get([
                'id',
                'report_code',
                'status',
                'row_count',
                'processed_rows',
                'chunks_completed',
                'failure_code',
                'completed_at',
                'updated_at',
            ])
            ->map(fn (object $row): array => [
                'report' => $this->reportTitle((string) $row->report_code),
                'status' => (string) $row->status,
                'row_count' => (int) $row->row_count,
                'processed_rows' => (int) $row->processed_rows,
                'chunks_completed' => (int) $row->chunks_completed,
                'failed' => $row->failure_code !== null,
                'completed_at' => $row->completed_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function recentSchedules(User $actor): array
    {
        if (! Schema::hasTable('enterprise_scheduled_reports')) {
            return [];
        }

        return DB::table('enterprise_scheduled_reports')
            ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
            ->orderByDesc('id')
            ->limit(12)
            ->get([
                'id',
                'report_code',
                'enabled',
                'interval_minutes',
                'next_run_at',
                'last_run_at',
                'last_error',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'report' => $this->reportTitle((string) $row->report_code),
                'enabled' => (bool) $row->enabled,
                'interval_minutes' => (int) $row->interval_minutes,
                'next_run_at' => $row->next_run_at,
                'last_run_at' => $row->last_run_at,
                'failed' => $row->last_error !== null,
            ])
            ->all();
    }

    /** @return array{failed_exports:int,failed_schedules:int,total:int} */
    private function exceptionSummary(User $actor): array
    {
        $failedExports = Schema::hasTable('enterprise_report_exports')
            ? (int) DB::table('enterprise_report_exports')
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
                ->where('status', 'failed')
                ->count()
            : 0;

        $failedSchedules = Schema::hasTable('enterprise_scheduled_reports')
            ? (int) DB::table('enterprise_scheduled_reports')
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
                ->whereNotNull('last_error')
                ->count()
            : 0;

        return [
            'failed_exports' => $failedExports,
            'failed_schedules' => $failedSchedules,
            'total' => $failedExports + $failedSchedules,
        ];
    }

    private function reportTitle(string $code): string
    {
        $key = self::REPORT_UI[$code]['key'] ?? null;

        return $key === null
            ? __('enterprise_bi_ui.values.unknown_report')
            : __('enterprise_bi_ui.reports.'.$key.'.title');
    }
}
