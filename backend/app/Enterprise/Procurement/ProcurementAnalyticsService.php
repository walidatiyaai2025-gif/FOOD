<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProcurementAnalyticsService
{
    public const REPORT_CODES = [
        'open_po',
        'spend',
        'supplier_performance',
        'savings',
        'contract_expiry',
        'match_exceptions',
    ];

    private const VIEW_PERMISSIONS = [
        'procurement.suppliers.view',
        'procurement.purchase_requests.view',
        'procurement.purchase_orders.view',
        'procurement.goods_receipts.view',
        'procurement.purchase_invoices.view',
        'procurement.supplier_statements.view',
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @return array<string,mixed> */
    public function dashboard(
        User $actor,
        ResolvedTenantContext $context,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $scope = $this->authorizedOverviewScope($actor, $context);
        [$fromDate, $toDate] = $this->dateRange($from, $to);

        $canRequests = $this->allows($actor, $context, $scope, 'procurement.purchase_requests.view');
        $canOrders = $this->allows($actor, $context, $scope, 'procurement.purchase_orders.view');
        $canInvoices = $this->allows($actor, $context, $scope, 'procurement.purchase_invoices.view');
        $canSuppliers = $this->allows($actor, $context, $scope, 'procurement.suppliers.view');
        $canStatements = $this->allows($actor, $context, $scope, 'procurement.supplier_statements.view');

        $metrics = [];
        if ($canRequests) {
            $openRequests = DB::table('enterprise_purchase_requests')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->whereIn('status', ['draft', 'submitted', 'approved'])
                ->count();
            $pendingApprovals = DB::table('enterprise_purchase_requests')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'submitted')
                ->count();
            $activeRfqs = DB::table('enterprise_procurement_rfqs')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->whereIn('status', ['published', 'evaluating'])
                ->count();

            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.open_requests'),
                $openRequests,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'requests']),
            );
            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.pending_approvals'),
                $pendingApprovals,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'requests', 'status' => 'submitted']),
                $pendingApprovals > 0 ? 'warning' : 'success',
            );
            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.active_rfqs'),
                $activeRfqs,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'sourcing']),
            );
        }

        if ($canOrders) {
            $baseOrders = DB::table('enterprise_purchase_orders')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId);
            $openOrders = (clone $baseOrders)->whereIn('status', ['draft', 'issued'])->count();
            $overdueOrders = (clone $baseOrders)
                ->where('status', 'issued')
                ->whereNotNull('needed_by')
                ->whereDate('needed_by', '<', now()->toDateString())
                ->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('enterprise_purchase_order_lines as order_line')
                        ->whereColumn('order_line.purchase_order_id', 'enterprise_purchase_orders.id')
                        ->whereRaw(
                            'order_line.quantity > COALESCE((SELECT SUM(receipt_line.received_quantity) FROM enterprise_goods_receipt_lines receipt_line JOIN enterprise_goods_receipts receipt ON receipt.id = receipt_line.goods_receipt_id WHERE receipt.status = ? AND receipt_line.purchase_order_line_id = order_line.id), 0)',
                            ['posted'],
                        );
                })
                ->count();

            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.open_pos'),
                $openOrders,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'orders']),
            );
            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.overdue_pos'),
                $overdueOrders,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'reports', 'report' => 'open_po']),
                $overdueOrders > 0 ? 'danger' : 'success',
            );
        }

        if ($canInvoices) {
            $matchExceptions = DB::table('enterprise_purchase_invoices as invoice')
                ->leftJoin('enterprise_procurement_match_reviews as review', 'review.purchase_invoice_id', '=', 'invoice.id')
                ->where('invoice.organization_id', $scope->organizationId)
                ->where('invoice.company_id', $scope->companyId)
                ->where('invoice.store_id', $scope->storeId)
                ->where('invoice.status', '!=', 'cancelled')
                ->where(function ($query): void {
                    $query->whereNull('review.id')
                        ->orWhereIn('review.status', ['pending', 'exception', 'rejected']);
                })
                ->count();

            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.match_exceptions'),
                $matchExceptions,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'matching']),
                $matchExceptions > 0 ? 'warning' : 'success',
            );
        }

        if ($canSuppliers) {
            $activeContracts = DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', now()->toDateString())
                ->whereDate('valid_to', '>=', now()->toDateString())
                ->count();
            $expiringContracts = DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'active')
                ->whereBetween('valid_to', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count();

            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.active_contracts'),
                $activeContracts,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'contracts']),
            );
            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.expiring_contracts'),
                $expiringContracts,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'contracts', 'expiring_days' => 30]),
                $expiringContracts > 0 ? 'warning' : 'success',
            );
        }

        if ($canStatements) {
            $overdueSupplierInvoices = DB::table('enterprise_purchase_invoices')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'handed_off')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count();

            $metrics[] = $this->metric(
                __('enterprise_procurement.dashboard.kpis.overdue_supplier_invoices'),
                $overdueSupplierInvoices,
                route('admin.erp.procurement.index', ['store_id' => $scope->storeId, 'tab' => 'statements']),
                $overdueSupplierInvoices > 0 ? 'warning' : 'success',
            );
        }

        return [
            'state' => 'current',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'metrics' => $metrics,
            'charts' => [
                'spendTrend' => $canInvoices ? $this->spendTrend($scope, $fromDate, $toDate) : collect(),
                'spendBySupplier' => $canInvoices ? $this->spendBySupplier($scope, $fromDate, $toDate) : collect(),
                'poStatus' => $canOrders ? $this->poStatus($scope) : collect(),
                'supplierPerformance' => $canOrders ? $this->supplierPerformance($scope, $fromDate, $toDate) : collect(),
                'savings' => $canOrders ? $this->savings($scope, $fromDate, $toDate) : collect(),
                'contractExpiry' => $canSuppliers ? $this->contractExpiry($scope) : collect(),
            ],
            'exceptions' => $this->exceptions($scope, $canOrders, $canInvoices, $canSuppliers),
            'last_refresh' => now()->format('Y-m-d H:i'),
        ];
    }

    /** @return array{rows:Collection<int,object|array<string,mixed>>,summary:array<string,mixed>} */
    public function report(
        User $actor,
        ResolvedTenantContext $context,
        string $code,
        array $filters = [],
    ): array {
        abort_unless(in_array($code, self::REPORT_CODES, true), 404);
        $permission = match ($code) {
            'spend', 'match_exceptions' => 'procurement.purchase_invoices.view',
            'supplier_performance', 'contract_expiry' => 'procurement.suppliers.view',
            default => 'procurement.purchase_orders.view',
        };
        $scope = $this->authorizedScope($actor, $context, $permission);
        [$from, $to] = $this->dateRange(
            is_string($filters['from'] ?? null) ? $filters['from'] : null,
            is_string($filters['to'] ?? null) ? $filters['to'] : null,
        );

        $rows = match ($code) {
            'open_po' => $this->openPoReport($scope),
            'spend' => $this->spendBySupplier($scope, $from, $to),
            'supplier_performance' => $this->supplierPerformance($scope, $from, $to),
            'savings' => $this->savings($scope, $from, $to),
            'contract_expiry' => $this->contractExpiryDetail($scope),
            'match_exceptions' => $this->matchExceptionReport($scope),
        };

        return [
            'rows' => $rows,
            'summary' => [
                'report' => $code,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'row_count' => $rows->count(),
            ],
        ];
    }

    /** @return Collection<int,object> */
    private function spendTrend(EnterpriseDataScope $scope, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'handed_off')
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('invoice_date as date, currency_code, COUNT(*) as invoice_count, SUM(total_amount) as spend')
            ->groupBy('invoice_date', 'currency_code')
            ->orderBy('invoice_date')
            ->get();
    }

    /** @return Collection<int,object> */
    private function spendBySupplier(EnterpriseDataScope $scope, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'handed_off')
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('supplier_id, supplier_code, currency_code, COUNT(*) as invoice_count, SUM(total_amount) as spend')
            ->groupBy('supplier_id', 'supplier_code', 'currency_code')
            ->orderByDesc('spend')
            ->limit(12)
            ->get();
    }

    /** @return Collection<int,object> */
    private function poStatus(EnterpriseDataScope $scope): Collection
    {
        return DB::table('enterprise_purchase_orders')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->selectRaw('status, COUNT(*) as orders')
            ->groupBy('status')
            ->orderByDesc('orders')
            ->get();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function supplierPerformance(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $receipts = DB::table('enterprise_goods_receipts as receipt')
            ->join('enterprise_purchase_orders as purchase_order', 'purchase_order.id', '=', 'receipt.purchase_order_id')
            ->where('receipt.organization_id', $scope->organizationId)
            ->where('receipt.company_id', $scope->companyId)
            ->where('receipt.store_id', $scope->storeId)
            ->where('receipt.status', 'posted')
            ->whereBetween('receipt.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('receipt.id')
            ->get([
                'receipt.supplier_id',
                'receipt.supplier_code',
                'receipt.posted_at',
                'purchase_order.issued_at',
                'purchase_order.needed_by',
            ])
            ->groupBy('supplier_id');

        return $receipts->map(function (Collection $rows): array {
            $leadDays = [];
            $onTime = 0;
            foreach ($rows as $row) {
                if ($row->issued_at !== null && $row->posted_at !== null) {
                    $leadDays[] = CarbonImmutable::parse((string) $row->issued_at)
                        ->diffInDays(CarbonImmutable::parse((string) $row->posted_at));
                }
                if ($row->needed_by !== null && $row->posted_at !== null
                    && CarbonImmutable::parse((string) $row->posted_at)->startOfDay()
                        ->lessThanOrEqualTo(CarbonImmutable::parse((string) $row->needed_by)->startOfDay())) {
                    $onTime++;
                }
            }

            return [
                'supplier_id' => (int) $rows->first()->supplier_id,
                'supplier_code' => (string) $rows->first()->supplier_code,
                'receipts' => $rows->count(),
                'on_time_rate' => $rows->count() > 0 ? round(($onTime / $rows->count()) * 100, 1) : 0.0,
                'average_lead_days' => $leadDays === [] ? null : round(array_sum($leadDays) / count($leadDays), 1),
            ];
        })->sortByDesc('receipts')->values()->take(12);
    }

    /** @return Collection<int,object> */
    private function savings(EnterpriseDataScope $scope, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('enterprise_purchase_orders as purchase_order')
            ->join('enterprise_purchase_order_lines as order_line', 'order_line.purchase_order_id', '=', 'purchase_order.id')
            ->join(
                'enterprise_purchase_request_lines as request_line',
                'request_line.id',
                '=',
                'order_line.source_purchase_request_line_id',
            )
            ->where('purchase_order.organization_id', $scope->organizationId)
            ->where('purchase_order.company_id', $scope->companyId)
            ->where('purchase_order.store_id', $scope->storeId)
            ->whereNotNull('purchase_order.sourcing_quote_id')
            ->whereBetween('purchase_order.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw(
                'purchase_order.supplier_id, purchase_order.supplier_code, purchase_order.currency_code, COUNT(order_line.id) as sourced_lines, SUM(order_line.quantity * COALESCE(request_line.estimated_unit_cost, order_line.unit_price)) as estimated_value, SUM(order_line.quantity * order_line.unit_price) as awarded_value, SUM(order_line.quantity * (COALESCE(request_line.estimated_unit_cost, order_line.unit_price) - order_line.unit_price)) as savings'
            )
            ->groupBy('purchase_order.supplier_id', 'purchase_order.supplier_code', 'purchase_order.currency_code')
            ->orderByDesc('savings')
            ->limit(12)
            ->get();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function contractExpiry(EnterpriseDataScope $scope): Collection
    {
        $today = CarbonImmutable::today();
        $buckets = ['0_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0, 'expired' => 0];

        DB::table('enterprise_procurement_contracts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'active')
            ->orderBy('valid_to')
            ->get(['valid_to'])
            ->each(function (object $contract) use (&$buckets, $today): void {
                $days = $today->diffInDays(CarbonImmutable::parse((string) $contract->valid_to), false);
                $bucket = $days < 0 ? 'expired' : ($days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
                $buckets[$bucket]++;
            });

        return collect($buckets)->map(
            static fn (int $count, string $bucket): array => ['bucket' => $bucket, 'contracts' => $count],
        )->values();
    }

    /** @return Collection<int,object> */
    private function contractExpiryDetail(EnterpriseDataScope $scope): Collection
    {
        return DB::table('enterprise_procurement_contracts as contract')
            ->join('enterprise_suppliers as supplier', 'supplier.id', '=', 'contract.supplier_id')
            ->where('contract.organization_id', $scope->organizationId)
            ->where('contract.company_id', $scope->companyId)
            ->where('contract.store_id', $scope->storeId)
            ->where('contract.status', 'active')
            ->orderBy('contract.valid_to')
            ->limit(200)
            ->get([
                'contract.id',
                'contract.contract_number',
                'supplier.code as supplier_code',
                'contract.title',
                'contract.currency_code',
                'contract.valid_from',
                'contract.valid_to',
                'contract.ceiling_amount',
                'contract.ceiling_quantity',
            ]);
    }

    /** @return Collection<int,object> */
    private function openPoReport(EnterpriseDataScope $scope): Collection
    {
        return DB::table('enterprise_purchase_orders as purchase_order')
            ->where('purchase_order.organization_id', $scope->organizationId)
            ->where('purchase_order.company_id', $scope->companyId)
            ->where('purchase_order.store_id', $scope->storeId)
            ->whereIn('purchase_order.status', ['draft', 'issued'])
            ->select([
                'purchase_order.id',
                'purchase_order.order_number',
                'purchase_order.supplier_id',
                'purchase_order.supplier_code',
                'purchase_order.status',
                'purchase_order.currency_code',
                'purchase_order.needed_by',
                'purchase_order.issued_at',
            ])
            ->selectSub(function ($query): void {
                $query->from('enterprise_purchase_order_lines as line')
                    ->whereColumn('line.purchase_order_id', 'purchase_order.id')
                    ->selectRaw('COALESCE(SUM(line.quantity * line.unit_price), 0)');
            }, 'ordered_value')
            ->selectSub(function ($query): void {
                $query->from('enterprise_goods_receipt_lines as receipt_line')
                    ->join('enterprise_goods_receipts as receipt', 'receipt.id', '=', 'receipt_line.goods_receipt_id')
                    ->whereColumn('receipt.purchase_order_id', 'purchase_order.id')
                    ->where('receipt.status', 'posted')
                    ->selectRaw('COALESCE(SUM(receipt_line.received_quantity * receipt_line.unit_price), 0)');
            }, 'received_value')
            ->orderByRaw('CASE WHEN purchase_order.needed_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('purchase_order.needed_by')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int,object> */
    private function matchExceptionReport(EnterpriseDataScope $scope): Collection
    {
        return DB::table('enterprise_purchase_invoices as invoice')
            ->leftJoin('enterprise_procurement_match_reviews as review', 'review.purchase_invoice_id', '=', 'invoice.id')
            ->where('invoice.organization_id', $scope->organizationId)
            ->where('invoice.company_id', $scope->companyId)
            ->where('invoice.store_id', $scope->storeId)
            ->where('invoice.status', '!=', 'cancelled')
            ->where(function ($query): void {
                $query->whereNull('review.id')->orWhereIn('review.status', ['pending', 'exception', 'rejected']);
            })
            ->orderByDesc('invoice.id')
            ->limit(200)
            ->get([
                'invoice.id as purchase_invoice_id',
                'invoice.invoice_number',
                'invoice.supplier_invoice_number',
                'invoice.supplier_id',
                'invoice.supplier_code',
                'invoice.currency_code',
                'invoice.total_amount',
                DB::raw("COALESCE(review.status, 'pending') as match_status"),
                'review.quantity_variance',
                'review.receipt_variance',
                'review.price_variance',
                'review.value_variance',
                'review.exception_reason',
            ]);
    }

    /** @return list<array<string,mixed>> */
    private function exceptions(
        EnterpriseDataScope $scope,
        bool $canOrders,
        bool $canInvoices,
        bool $canSuppliers,
    ): array {
        $items = [];
        if ($canOrders) {
            foreach ($this->openPoReport($scope)->take(5) as $row) {
                if ($row->needed_by !== null && CarbonImmutable::parse((string) $row->needed_by)->isPast()) {
                    $items[] = [
                        'type' => 'overdue_po',
                        'reference' => $row->order_number,
                        'label' => __('enterprise_procurement.dashboard.exceptions.overdue_po'),
                        'url' => route('admin.erp.procurement.index', [
                            'store_id' => $scope->storeId,
                            'tab' => 'orders',
                            'order' => $row->id,
                        ]),
                    ];
                }
            }
        }
        if ($canInvoices) {
            foreach ($this->matchExceptionReport($scope)->take(5) as $row) {
                $items[] = [
                    'type' => 'match_exception',
                    'reference' => $row->invoice_number,
                    'label' => __('enterprise_procurement.dashboard.exceptions.match_exception'),
                    'url' => route('admin.erp.procurement.index', [
                        'store_id' => $scope->storeId,
                        'tab' => 'matching',
                        'invoice' => $row->purchase_invoice_id,
                    ]),
                ];
            }
        }
        if ($canSuppliers) {
            $expiredDocuments = DB::table('enterprise_supplier_documents as document')
                ->join('enterprise_suppliers as supplier', 'supplier.id', '=', 'document.supplier_id')
                ->where('supplier.organization_id', $scope->organizationId)
                ->where('supplier.company_id', $scope->companyId)
                ->where(function ($query) use ($scope): void {
                    $query->where('supplier.scope_type', 'company')
                        ->orWhere('supplier.store_id', $scope->storeId);
                })
                ->whereNotNull('document.expires_at')
                ->whereDate('document.expires_at', '<=', now()->addDays(30)->toDateString())
                ->orderBy('document.expires_at')
                ->limit(5)
                ->get(['supplier.id', 'supplier.code', 'document.title']);

            foreach ($expiredDocuments as $row) {
                $items[] = [
                    'type' => 'supplier_document',
                    'reference' => $row->code,
                    'label' => __('enterprise_procurement.dashboard.exceptions.supplier_document'),
                    'url' => route('admin.erp.procurement.index', [
                        'store_id' => $scope->storeId,
                        'tab' => 'suppliers',
                        'supplier' => $row->id,
                    ]),
                ];
            }
        }

        return array_slice($items, 0, 10);
    }

    /** @return array<string,mixed> */
    private function metric(
        string $label,
        int|float|string $value,
        ?string $url = null,
        string $state = 'current',
    ): array {
        return [
            'label' => $label,
            'value' => is_float($value) ? number_format($value, 3) : (string) $value,
            'status' => [
                'label' => __('enterprise_procurement.dashboard.states.'.$state),
                'state' => $state,
            ],
            'last_refresh' => now()->format('Y-m-d H:i'),
            'drilldown_url' => $url,
        ];
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function dateRange(?string $from, ?string $to): array
    {
        $today = CarbonImmutable::today();
        $validated = Validator::make(
            ['from' => $from, 'to' => $to],
            [
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ],
        )->validate();

        $fromDate = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'])
            : $today->subDays(29);
        $toDate = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'])
            : $today;

        abort_if($fromDate->diffInDays($toDate) > 365, 422, 'Procurement dashboard range cannot exceed 366 days.');

        return [$fromDate, $toDate];
    }

    private function authorizedOverviewScope(User $actor, ResolvedTenantContext $context): EnterpriseDataScope
    {
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;

        $allowed = collect(self::VIEW_PERMISSIONS)->contains(
            fn (string $permission): bool => $this->allows($actor, $context, $scope, $permission),
        );
        abort_unless($allowed, 403);

        return $scope;
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize($actor, $context, $permission, 'company', $scope->companyId);

        return $scope;
    }

    private function allows(
        User $actor,
        ResolvedTenantContext $context,
        EnterpriseDataScope $scope,
        string $permission,
    ): bool {
        return $this->authorizer->allows($actor, $context, $permission, 'company', $scope->companyId);
    }
}
