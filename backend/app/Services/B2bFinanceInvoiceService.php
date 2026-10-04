<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class B2bFinanceInvoiceService
{
    /**
     * @param  list<int>  $storeIds
     * @param  array{from?:?string,to?:?string,customer_id?:?int}  $filters
     * @return array<string, mixed>
     */
    public function viewModel(array $storeIds, array $filters, string $locale): array
    {
        $rows = $this->filteredRows($storeIds, $filters);
        $isAr = $locale === 'ar';

        return [
            'columns' => ['invoice', 'company', 'client', 'status', 'amount', 'paid', 'balance', 'issued_at', 'due', 'actions'],
            'rows' => $rows->map(function (object $row) use ($isAr): array {
                $total = (float) $row->total;
                $paid = (float) $row->paid_total;

                return [
                    '_id' => (int) $row->id,
                    'invoice' => (string) $row->invoice_number,
                    'company' => $row->company_name ?: '-',
                    'client' => (string) $row->client_name,
                    'status' => (string) $row->status,
                    'amount' => (string) $row->currency.' '.number_format($total, 3),
                    'paid' => (string) $row->currency.' '.number_format($paid, 3),
                    'balance' => (string) $row->currency.' '.number_format(max(0, $total - $paid), 3),
                    'issued_at' => $row->issued_at === null ? '-' : (string) $row->issued_at,
                    'due' => $row->due_at === null ? '-' : (string) $row->due_at,
                    'actions' => [
                        [
                            'label' => $isAr ? 'تفاصيل' : 'Details',
                            'url' => route('admin.invoices.show', ['invoice' => $row->id]),
                        ],
                        [
                            'label' => 'PDF',
                            'url' => route('admin.invoices.download', ['invoice' => $row->id, 'locale' => $locale]),
                        ],
                    ],
                ];
            })->all(),
            'filters' => $this->normalizedFilters($filters),
            'customers' => $this->customerOptions($storeIds),
            'summary' => [
                'invoice_count' => $rows->count(),
                'totals' => $this->currencyTotals($rows),
            ],
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @param  array{from?:?string,to?:?string,customer_id?:?int}  $filters
     * @return array<string, mixed>
     */
    public function exportReport(array $storeIds, array $filters, string $locale): array
    {
        $rows = $this->filteredRows($storeIds, $filters);
        $normalized = $this->normalizedFilters($filters);
        $customerId = $normalized['customer_id'];
        $customer = $customerId === null
            ? null
            : collect($this->customerOptions($storeIds))->firstWhere('id', $customerId);

        return [
            'report' => 'finance_invoices',
            'generated_at' => now()->toIso8601String(),
            'filters' => $normalized,
            'kpis' => [
                'invoice_count' => $rows->count(),
                'customer_filter' => $customerId === null
                    ? ($locale === 'ar' ? 'الكل' : 'All')
                    : ($customer['label'] ?? '#'.$customerId),
                'currency_totals' => $this->currencyTotalsText($rows),
            ],
            'columns' => [
                'invoice',
                'company',
                'client',
                'status',
                'currency',
                'invoice_total',
                'paid',
                'balance',
                'issued_at',
                'due',
            ],
            'rows' => $rows->map(function (object $row): array {
                $total = (float) $row->total;
                $paid = (float) $row->paid_total;

                return [
                    'invoice' => (string) $row->invoice_number,
                    'company' => $row->company_name ?: '',
                    'client' => (string) $row->client_name,
                    'status' => (string) $row->status,
                    'currency' => (string) $row->currency,
                    'invoice_total' => $total,
                    'paid' => $paid,
                    'balance' => max(0, $total - $paid),
                    'issued_at' => $row->issued_at === null ? '' : (string) $row->issued_at,
                    'due' => $row->due_at === null ? '' : (string) $row->due_at,
                ];
            })->all(),
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @param  array{from?:?string,to?:?string,customer_id?:?int}  $filters
     * @return Collection<int, object>
     */
    private function filteredRows(array $storeIds, array $filters): Collection
    {
        if ($storeIds === []) {
            return collect();
        }

        $paid = DB::table('payments')
            ->select('invoice_id')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_total")
            ->groupBy('invoice_id');

        return DB::table('invoices')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'invoices.b2b_customer_id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoinSub($paid, 'invoice_payments', function ($join): void {
                $join->on('invoice_payments.invoice_id', '=', 'invoices.id');
            })
            ->whereNotNull('invoices.b2b_customer_id')
            ->whereIn('invoices.store_id', $storeIds)
            ->where('invoices.channel', 'b2b')
            ->when($filters['from'] ?? null, function ($query, string $from): void {
                $query->whereDate('invoices.issued_at', '>=', $from);
            })
            ->when($filters['to'] ?? null, function ($query, string $to): void {
                $query->whereDate('invoices.issued_at', '<=', $to);
            })
            ->when($filters['customer_id'] ?? null, function ($query, int|string $customerId): void {
                $query->where('invoices.b2b_customer_id', (int) $customerId);
            })
            ->orderByDesc('invoices.issued_at')
            ->orderByDesc('invoices.id')
            ->get([
                'invoices.id',
                'invoices.invoice_number',
                'invoices.status',
                'invoices.currency',
                'invoices.total',
                'invoices.issued_at',
                'invoices.due_at',
                'b2b_customers.id as customer_id',
                'b2b_customers.name as client_name',
                'b2b_accounts.company_name',
                DB::raw('COALESCE(invoice_payments.paid_total, 0) as paid_total'),
            ]);
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array{id:int,label:string}>
     */
    private function customerOptions(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return DB::table('b2b_customers')
            ->join('invoices', 'invoices.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->whereIn('invoices.store_id', $storeIds)
            ->where('invoices.channel', 'b2b')
            ->whereNotNull('invoices.b2b_customer_id')
            ->orderBy('b2b_customers.name')
            ->get([
                'b2b_customers.id',
                'b2b_customers.name',
                'b2b_accounts.company_name',
            ])
            ->unique('id')
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'label' => trim(($row->company_name ? $row->company_name.' · ' : '').$row->name),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{from?:?string,to?:?string,customer_id?:?int}  $filters
     * @return array{from:?string,to:?string,customer_id:?int}
     */
    private function normalizedFilters(array $filters): array
    {
        return [
            'from' => isset($filters['from']) && $filters['from'] !== '' ? (string) $filters['from'] : null,
            'to' => isset($filters['to']) && $filters['to'] !== '' ? (string) $filters['to'] : null,
            'customer_id' => isset($filters['customer_id']) && $filters['customer_id'] !== null
                ? (int) $filters['customer_id']
                : null,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array{currency:string,total:float,paid:float,balance:float}>
     */
    private function currencyTotals(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) $row->currency)
            ->map(function (Collection $currencyRows, string $currency): array {
                $total = (float) $currencyRows->sum(fn (object $row): float => (float) $row->total);
                $paid = (float) $currencyRows->sum(fn (object $row): float => (float) $row->paid_total);

                return [
                    'currency' => $currency,
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => max(0, $total - $paid),
                ];
            })
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $rows */
    private function currencyTotalsText(Collection $rows): string
    {
        $totals = $this->currencyTotals($rows);
        if ($totals === []) {
            return '-';
        }

        return collect($totals)
            ->map(fn (array $row): string => sprintf(
                '%s %.3f / %.3f / %.3f',
                $row['currency'],
                $row['total'],
                $row['paid'],
                $row['balance'],
            ))
            ->implode(' | ');
    }
}
