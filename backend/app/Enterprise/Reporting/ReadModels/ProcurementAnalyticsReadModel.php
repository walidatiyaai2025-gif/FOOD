<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProcurementAnalyticsReadModel
{
    public function read(ReportQuery $query, EntitlementScope $scope): array
    {
        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Procurement analytics require an explicit store scope.'],
            ]);
        }

        if (! str_starts_with($query->report->code, 'procurement.')
            || ! str_starts_with($query->report->readModel, 'procurement_')) {
            throw ValidationException::withMessages([
                'report' => ['Procurement analytics require a registered procurement report definition.'],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today();
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);

        $builder = match ($query->report->readModel) {
            'procurement_open_po' => $this->openPo($query, $scope->id),
            'procurement_spend' => $this->spend($query, $scope->id, $from, $to),
            'procurement_supplier_performance' => $this->supplierPerformance($query, $scope->id, $from, $to),
            'procurement_savings' => $this->savings($query, $scope->id, $from, $to),
            'procurement_contract_expiry' => $this->contractExpiry($query, $scope->id),
            'procurement_match_exceptions' => $this->matchExceptions($query, $scope->id),
            default => throw ValidationException::withMessages([
                'read_model' => ["Unsupported procurement read model [{$query->report->readModel}]."],
            ]),
        };

        $this->applySort($builder, $query);
        $rows = $builder
            ->offset(($query->page - 1) * $query->perPage)
            ->limit($query->perPage + 1)
            ->get();
        $hasMore = $rows->count() > $query->perPage;

        return [
            'rows' => $rows->take($query->perPage)
                ->map(fn (\stdClass $row): array => $this->mapRow($row, $query, $scope->id))
                ->values()
                ->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    private function openPo(ReportQuery $query, int $storeId): Builder
    {
        $received = DB::table('enterprise_goods_receipt_lines as receipt_line')
            ->join('enterprise_goods_receipts as receipt', 'receipt.id', '=', 'receipt_line.goods_receipt_id')
            ->where('receipt.status', 'posted')
            ->selectRaw(
                'receipt_line.purchase_order_line_id, SUM(receipt_line.received_quantity) as received_quantity'
            )
            ->groupBy('receipt_line.purchase_order_line_id');

        $builder = DB::table('enterprise_purchase_orders as purchase_order')
            ->join('enterprise_purchase_order_lines as line', 'line.purchase_order_id', '=', 'purchase_order.id')
            ->leftJoinSub($received, 'received', 'received.purchase_order_line_id', '=', 'line.id')
            ->where('purchase_order.store_id', $storeId)
            ->whereIn('purchase_order.status', ['draft', 'issued'])
            ->whereRaw('line.quantity > COALESCE(received.received_quantity, 0)');

        $dimensionMap = [
            'supplier_id' => 'purchase_order.supplier_id',
            'status' => 'purchase_order.status',
            'currency' => 'purchase_order.currency_code',
        ];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $measureMap = [
            'purchase_orders' => 'COUNT(DISTINCT purchase_order.id)',
            'ordered_value' => 'COALESCE(SUM(line.quantity * COALESCE(line.unit_price, 0)), 0)',
            'received_value' => 'COALESCE(SUM(COALESCE(received.received_quantity, 0) * COALESCE(line.unit_price, 0)), 0)',
            'outstanding_value' => 'COALESCE(SUM((line.quantity - COALESCE(received.received_quantity, 0)) * COALESCE(line.unit_price, 0)), 0)',
        ];
        $this->selectMeasures($builder, $query, $measureMap);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, [
            'supplier_id' => 'purchase_order.supplier_id',
            'status' => 'purchase_order.status',
            'currency' => 'purchase_order.currency_code',
        ]);

        if (array_key_exists('overdue', $query->filters) && $this->boolFilter($query->filters['overdue'])) {
            $builder->where('purchase_order.status', 'issued')
                ->whereNotNull('purchase_order.needed_by')
                ->whereDate('purchase_order.needed_by', '<', CarbonImmutable::today()->toDateString());
        }

        return $builder;
    }

    private function spend(
        ReportQuery $query,
        int $storeId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Builder {
        $builder = DB::table('enterprise_purchase_invoices as invoice')
            ->where('invoice.store_id', $storeId)
            ->where('invoice.status', 'handed_off')
            ->whereBetween('invoice.invoice_date', [$from->toDateString(), $to->toDateString()]);

        $dimensionMap = [
            'supplier_id' => 'invoice.supplier_id',
            'currency' => 'invoice.currency_code',
        ];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $this->selectMeasures($builder, $query, [
            'invoices' => 'COUNT(*)',
            'spend' => 'COALESCE(SUM(invoice.total_amount), 0)',
        ]);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, [
            'supplier_id' => 'invoice.supplier_id',
            'currency' => 'invoice.currency_code',
        ]);

        return $builder;
    }

    private function supplierPerformance(
        ReportQuery $query,
        int $storeId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Builder {
        $builder = DB::table('enterprise_goods_receipts as receipt')
            ->join('enterprise_purchase_orders as purchase_order', 'purchase_order.id', '=', 'receipt.purchase_order_id')
            ->where('receipt.store_id', $storeId)
            ->where('receipt.status', 'posted')
            ->whereBetween('receipt.posted_at', [$from->startOfDay(), $to->endOfDay()]);

        $dimensionMap = ['supplier_id' => 'receipt.supplier_id'];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $this->selectMeasures($builder, $query, [
            'receipts' => 'COUNT(*)',
            'on_time_rate' => 'CASE WHEN COUNT(*) = 0 THEN 0 ELSE 100.0 * SUM(CASE WHEN purchase_order.needed_by IS NOT NULL AND DATE(receipt.posted_at) <= purchase_order.needed_by THEN 1 ELSE 0 END) / COUNT(*) END',
            'average_lead_days' => 'AVG(CASE WHEN purchase_order.issued_at IS NULL THEN NULL ELSE TIMESTAMPDIFF(DAY, purchase_order.issued_at, receipt.posted_at) END)',
        ]);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, ['supplier_id' => 'receipt.supplier_id']);

        return $builder;
    }

    private function savings(
        ReportQuery $query,
        int $storeId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Builder {
        $builder = DB::table('enterprise_purchase_orders as purchase_order')
            ->join('enterprise_purchase_order_lines as line', 'line.purchase_order_id', '=', 'purchase_order.id')
            ->join(
                'enterprise_purchase_request_lines as request_line',
                'request_line.id',
                '=',
                'line.source_purchase_request_line_id',
            )
            ->where('purchase_order.store_id', $storeId)
            ->whereNotNull('purchase_order.sourcing_quote_id')
            ->whereBetween('purchase_order.created_at', [$from->startOfDay(), $to->endOfDay()]);

        $dimensionMap = [
            'supplier_id' => 'purchase_order.supplier_id',
            'currency' => 'purchase_order.currency_code',
        ];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $this->selectMeasures($builder, $query, [
            'sourced_lines' => 'COUNT(line.id)',
            'estimated_value' => 'COALESCE(SUM(line.quantity * COALESCE(request_line.estimated_unit_cost, line.unit_price, 0)), 0)',
            'awarded_value' => 'COALESCE(SUM(line.quantity * COALESCE(line.unit_price, 0)), 0)',
            'savings' => 'COALESCE(SUM(line.quantity * (COALESCE(request_line.estimated_unit_cost, line.unit_price, 0) - COALESCE(line.unit_price, 0))), 0)',
        ]);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, [
            'supplier_id' => 'purchase_order.supplier_id',
            'currency' => 'purchase_order.currency_code',
        ]);

        return $builder;
    }

    private function contractExpiry(ReportQuery $query, int $storeId): Builder
    {
        $days = "CASE
            WHEN contract.valid_to < CURRENT_DATE THEN 'expired'
            WHEN contract.valid_to <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN '0_30'
            WHEN contract.valid_to <= DATE_ADD(CURRENT_DATE, INTERVAL 60 DAY) THEN '31_60'
            WHEN contract.valid_to <= DATE_ADD(CURRENT_DATE, INTERVAL 90 DAY) THEN '61_90'
            ELSE 'over_90'
        END";

        $builder = DB::table('enterprise_procurement_contracts as contract')
            ->where('contract.store_id', $storeId)
            ->where('contract.status', 'active');

        $dimensionMap = [
            'supplier_id' => 'contract.supplier_id',
            'expiry_bucket' => $days,
            'currency' => 'contract.currency_code',
        ];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $this->selectMeasures($builder, $query, [
            'contracts' => 'COUNT(*)',
            'ceiling_amount' => 'COALESCE(SUM(contract.ceiling_amount), 0)',
        ]);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, [
            'supplier_id' => 'contract.supplier_id',
            'currency' => 'contract.currency_code',
        ]);

        return $builder;
    }

    private function matchExceptions(ReportQuery $query, int $storeId): Builder
    {
        $builder = DB::table('enterprise_purchase_invoices as invoice')
            ->leftJoin('enterprise_procurement_match_reviews as review', 'review.purchase_invoice_id', '=', 'invoice.id')
            ->where('invoice.store_id', $storeId)
            ->where('invoice.status', '!=', 'cancelled')
            ->where(function (Builder $nested): void {
                $nested->whereNull('review.id')
                    ->orWhereIn('review.status', ['pending', 'exception', 'rejected']);
            });

        $dimensionMap = [
            'supplier_id' => 'invoice.supplier_id',
            'match_status' => "COALESCE(review.status, 'pending')",
            'currency' => 'invoice.currency_code',
        ];
        $this->selectDimensions($builder, $query, $dimensionMap);
        $this->selectMeasures($builder, $query, [
            'invoices' => 'COUNT(*)',
            'quantity_variance' => 'COALESCE(SUM(review.quantity_variance), 0)',
            'price_variance' => 'COALESCE(SUM(review.price_variance), 0)',
            'value_variance' => 'COALESCE(SUM(review.value_variance), 0)',
        ]);
        $this->groupDimensions($builder, $query, $dimensionMap);
        $this->filterCommon($builder, $query, [
            'supplier_id' => 'invoice.supplier_id',
            'match_status' => "COALESCE(review.status, 'pending')",
            'currency' => 'invoice.currency_code',
        ]);

        return $builder;
    }

    /** @param array<string,string> $map */
    private function selectDimensions(Builder $builder, ReportQuery $query, array $map): void
    {
        foreach ($query->dimensions as $dimension) {
            $expression = $map[$dimension] ?? null;
            if ($expression === null) {
                throw ValidationException::withMessages([
                    'dimensions' => ["Unsupported procurement dimension [{$dimension}]."],
                ]);
            }
            $builder->selectRaw($expression.' as '.$dimension);
        }
    }

    /** @param array<string,string> $map */
    private function selectMeasures(Builder $builder, ReportQuery $query, array $map): void
    {
        foreach ($query->measures as $measure) {
            $expression = $map[$measure] ?? null;
            if ($expression === null) {
                throw ValidationException::withMessages([
                    'measures' => ["Unsupported procurement measure [{$measure}]."],
                ]);
            }
            $builder->selectRaw($expression.' as '.$measure);
        }
    }

    /** @param array<string,string> $map */
    private function groupDimensions(Builder $builder, ReportQuery $query, array $map): void
    {
        foreach ($query->groupBy as $dimension) {
            $expression = $map[$dimension] ?? null;
            if ($expression === null) {
                throw ValidationException::withMessages([
                    'group_by' => ["Unsupported procurement grouping [{$dimension}]."],
                ]);
            }
            $builder->groupByRaw($expression);
        }
    }

    /** @param array<string,string> $map */
    private function filterCommon(Builder $builder, ReportQuery $query, array $map): void
    {
        foreach ($query->filters as $key => $value) {
            if ($key === 'overdue') {
                continue;
            }
            $column = $map[$key] ?? null;
            if ($column === null) {
                throw ValidationException::withMessages([
                    'filters' => ["Unsupported procurement filter [{$key}]."],
                ]);
            }
            $builder->whereRaw($column.' = ?', [$key === 'currency' ? strtoupper((string) $value) : $value]);
        }
    }

    private function applySort(Builder $builder, ReportQuery $query): void
    {
        $selected = array_values(array_unique([...$query->dimensions, ...$query->measures]));
        foreach ($query->sort as $sort) {
            if (! in_array($sort['field'], $selected, true)) {
                continue;
            }
            $builder->orderBy($sort['field'], $sort['direction']);
        }

        if ($query->sort === []) {
            foreach ($query->dimensions as $dimension) {
                $builder->orderBy($dimension);
            }
        }
    }

    /** @return array<string,mixed> */
    private function mapRow(\stdClass $row, ReportQuery $query, int $storeId): array
    {
        $result = [];
        foreach ($query->dimensions as $dimension) {
            $value = $row->{$dimension};
            $result[$dimension] = $dimension === 'supplier_id' ? (int) $value : (string) $value;
        }
        foreach ($query->measures as $measure) {
            $value = $row->{$measure};
            $result[$measure] = in_array($measure, ['purchase_orders', 'invoices', 'receipts', 'sourced_lines', 'contracts'], true)
                ? (int) $value
                : number_format((float) $value, 3, '.', '');
        }

        $result['drill_down'] = [
            'store_id' => $storeId,
            ...array_intersect_key($result, array_flip(['supplier_id', 'status', 'currency', 'match_status', 'expiry_bucket'])),
        ];

        return $result;
    }

    private function boolFilter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
