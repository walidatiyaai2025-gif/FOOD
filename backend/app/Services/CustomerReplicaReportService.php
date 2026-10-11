<?php

namespace App\Services;

use App\Models\B2bCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerReplicaReportService
{
    public const ACCOUNT_STATEMENT = 'account-statement';

    public const ITEM_DETAIL = 'item-detail';

    public const ITEM_SUMMARY = 'item-summary';

    public const TYPES = [
        self::ACCOUNT_STATEMENT,
        self::ITEM_DETAIL,
        self::ITEM_SUMMARY,
    ];

    public function __construct(private readonly B2bAccountLedgerService $ledger) {}

    /** @return array<string,mixed> */
    public function build(B2bCustomer $customer, string $report, string $from, string $to): array
    {
        if (! in_array($report, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported customer replica report.');
        }

        return match ($report) {
            self::ACCOUNT_STATEMENT => $this->accountStatement($customer, $from, $to),
            self::ITEM_DETAIL => $this->itemDetail($customer, $from, $to),
            self::ITEM_SUMMARY => $this->itemSummary($customer, $from, $to),
        };
    }

    /** @return array<string,mixed> */
    private function accountStatement(B2bCustomer $customer, string $from, string $to): array
    {
        $statement = $this->ledger->statement($customer, $from, $to);

        $rows = collect($statement['transactions'] ?? [])
            ->map(function (array $row): array {
                $type = (string) ($row['type'] ?? '');
                $reference = trim((string) ($row['reference'] ?? ''));
                $documentType = $this->documentType($type);
                $movementNumber = $reference !== '' ? $reference : $this->fallbackMovementNumber($row);
                $description = $this->movementDescription($type, $movementNumber, (string) ($row['description'] ?? ''));

                return [
                    'date' => CarbonImmutable::parse((string) $row['occurred_at'])->format('Y/m/d'),
                    'movement_number' => $movementNumber,
                    'document_type' => $documentType,
                    'description' => $description,
                    'debit' => round((float) ($row['debit'] ?? 0), 3),
                    'credit' => round((float) ($row['credit'] ?? 0), 3),
                    'balance_debit' => (float) ($row['running_balance'] ?? 0) < 0
                        ? abs(round((float) $row['running_balance'], 3))
                        : 0.0,
                    'balance_credit' => (float) ($row['running_balance'] ?? 0) > 0
                        ? round((float) $row['running_balance'], 3)
                        : 0.0,
                ];
            })
            ->values()
            ->all();

        $opening = round((float) ($statement['opening_balance'] ?? 0), 3);
        $closing = round((float) ($statement['closing_balance'] ?? 0), 3);

        return [
            ...$this->base($customer, self::ACCOUNT_STATEMENT, $from, $to),
            'currency' => (string) ($statement['currency'] ?? ''),
            'opening_debit' => $opening < 0 ? abs($opening) : 0.0,
            'opening_credit' => $opening > 0 ? $opening : 0.0,
            'rows' => $rows,
            'total_debit' => round((float) ($statement['period_debits'] ?? 0), 3),
            'total_credit' => round((float) ($statement['period_credits'] ?? 0), 3),
            'closing_debit' => $closing < 0 ? abs($closing) : 0.0,
            'closing_credit' => $closing > 0 ? $closing : 0.0,
        ];
    }

    /** @return array<string,mixed> */
    private function itemDetail(B2bCustomer $customer, string $from, string $to): array
    {
        $rows = $this->itemRows($customer, $from, $to);
        $sellerByOrder = $this->sellerByOrder(
            $rows->pluck('order_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
        );

        $detail = $rows->map(function (object $row) use ($customer, $sellerByOrder): array {
            $quantity = round((float) $row->quantity, 3);
            $lineTotal = round((float) $row->line_total, 3);
            $isReturn = $quantity < 0 || $lineTotal < 0;
            $movementQuantity = $isReturn ? 0 - abs($quantity) : abs($quantity);
            $movementValue = $isReturn ? 0 - abs($lineTotal) : abs($lineTotal);
            $orderId = $row->order_id === null ? null : (int) $row->order_id;

            return [
                'date' => CarbonImmutable::parse((string) ($row->issued_at ?? $row->invoice_created_at))->format('Y/m/d'),
                'movement_number' => (string) $row->invoice_number,
                'movement_type' => $isReturn ? 'مرتجعات' : 'مبيعات',
                'customer' => (string) $customer->name,
                'item' => (string) $row->description,
                'item_code' => trim((string) ($row->sku_snapshot ?? '')) !== ''
                    ? (string) $row->sku_snapshot
                    : (trim((string) ($row->sku ?? '')) !== ''
                        ? (string) $row->sku
                        : ($row->product_id === null ? '' : (string) $row->product_id)),
                'quantity' => $movementQuantity,
                'price' => round((float) $row->unit_price, 3),
                'net_value' => $movementValue,
                'seller' => $orderId === null ? '' : (string) ($sellerByOrder[$orderId] ?? ''),
                'notes' => '',
                'category' => (string) ($row->category_name ?? ''),
                'unit' => (string) ($row->unit_name ?? ''),
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
            ];
        })->values();

        return [
            ...$this->base($customer, self::ITEM_DETAIL, $from, $to),
            'rows' => $detail->all(),
            'total' => round((float) $detail->sum('net_value'), 3),
        ];
    }

    /** @return array<string,mixed> */
    private function itemSummary(B2bCustomer $customer, string $from, string $to): array
    {
        /** @var Collection<int,array<string,mixed>> $detail */
        $detail = collect($this->itemDetail($customer, $from, $to)['rows']);

        $rows = $detail
            ->groupBy(function (array $row): string {
                $productKey = $row['product_id'] === null
                    ? 'snapshot:'.(string) $row['item_code'].'|'.(string) $row['item']
                    : 'product:'.(string) $row['product_id'];

                return $productKey.'|'.(string) $row['unit'].'|'.(string) $row['category'];
            })
            ->map(function (Collection $group): array {
                $first = (array) $group->first();
                $sales = round((float) $group
                    ->filter(fn (array $row): bool => (float) $row['quantity'] > 0)
                    ->sum(fn (array $row): float => abs((float) $row['quantity'])), 3);
                $returns = round((float) $group
                    ->filter(fn (array $row): bool => (float) $row['quantity'] < 0)
                    ->sum(fn (array $row): float => abs((float) $row['quantity'])), 3);

                return [
                    'item_code' => (string) $first['item_code'],
                    'item' => (string) $first['item'],
                    'category' => (string) $first['category'],
                    'sales' => $sales,
                    'returns' => $returns,
                    'net_sold' => round($sales - $returns, 3),
                    'unit' => (string) $first['unit'],
                    'net_value' => round((float) $group->sum('net_value'), 3),
                ];
            })
            ->sortBy('item_code', SORT_NATURAL)
            ->values();

        return [
            ...$this->base($customer, self::ITEM_SUMMARY, $from, $to),
            'rows' => $rows->all(),
            'total' => round((float) $rows->sum('net_value'), 3),
        ];
    }

    /** @return Collection<int,object> */
    private function itemRows(B2bCustomer $customer, string $from, string $to): Collection
    {
        $query = DB::table('invoices')
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('invoices.b2b_customer_id', $customer->getKey())
            ->whereNotIn('invoices.status', ['cancelled', 'canceled', 'void', 'voided']);

        $this->applyInvoiceDateRange($query, $from, $to);

        return $query
            ->orderByRaw('COALESCE(invoices.issued_at, invoices.created_at)')
            ->orderBy('invoices.id')
            ->orderBy('invoice_items.id')
            ->get([
                'invoices.id as invoice_id',
                'invoices.order_id',
                'invoices.invoice_number',
                'invoices.issued_at',
                'invoices.created_at as invoice_created_at',
                'invoice_items.id as item_id',
                'invoice_items.product_id',
                'invoice_items.sku_snapshot',
                'invoice_items.description',
                'invoice_items.quantity',
                'invoice_items.unit_price',
                'invoice_items.line_total',
                'products.sku',
                'categories.name as category_name',
                'units.name as unit_name',
            ]);
    }

    private function applyInvoiceDateRange(QueryBuilder $query, string $from, string $to): void
    {
        $query
            ->where(function ($scope) use ($from): void {
                $scope->whereDate('invoices.issued_at', '>=', $from)
                    ->orWhere(function ($fallback) use ($from): void {
                        $fallback->whereNull('invoices.issued_at')
                            ->whereDate('invoices.created_at', '>=', $from);
                    });
            })
            ->where(function ($scope) use ($to): void {
                $scope->whereDate('invoices.issued_at', '<=', $to)
                    ->orWhere(function ($fallback) use ($to): void {
                        $fallback->whereNull('invoices.issued_at')
                            ->whereDate('invoices.created_at', '<=', $to);
                    });
            });
    }

    /** @param array<int,int> $orderIds
     * @return array<int,string>
     */
    private function sellerByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('order_van_assignments')
            ->leftJoin('van_assignments', 'van_assignments.id', '=', 'order_van_assignments.van_assignment_id')
            ->leftJoin('users', 'users.id', '=', 'van_assignments.representative_user_id')
            ->whereIn('order_van_assignments.order_id', $orderIds)
            ->whereNotNull('users.name')
            ->orderByDesc('order_van_assignments.assigned_at')
            ->orderByDesc('order_van_assignments.id')
            ->get(['order_van_assignments.order_id', 'users.name'])
            ->groupBy('order_id')
            ->map(function (Collection $rows): string {
                $row = $rows->first();

                return is_object($row) && isset($row->name) ? (string) $row->name : '';
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function base(B2bCustomer $customer, string $report, string $from, string $to): array
    {
        return [
            'report' => $report,
            'from' => CarbonImmutable::parse($from)->format('Y/m/d'),
            'to' => CarbonImmutable::parse($to)->format('Y/m/d'),
            'from_iso' => $from,
            'to_iso' => $to,
            'customer_code' => $customer->legacy_customer_id === null
                ? (string) $customer->getKey()
                : (string) $customer->legacy_customer_id,
            'customer_name' => (string) $customer->name,
            'generated_at' => now(),
        ];
    }

    /** @param array<string,mixed> $row */
    private function fallbackMovementNumber(array $row): string
    {
        $id = (string) ($row['id'] ?? '');

        return str_contains($id, ':') ? (string) str($id)->after(':') : $id;
    }

    private function documentType(string $type): string
    {
        return match ($type) {
            'invoice' => 'مبيعات سيارة',
            'payment' => 'قبض',
            'return' => 'مرتجعات سيارة',
            'credit_note', 'customer_credit', 'adjustment_negative' => 'إشعار دائن',
            'debit_note', 'refund', 'adjustment_positive' => 'إشعار مدين',
            default => 'قيد حساب',
        };
    }

    private function movementDescription(string $type, string $movementNumber, string $fallback): string
    {
        return match ($type) {
            'invoice' => 'نوع المستند مبيعات سيارة البيان فاتورة رقم '.$movementNumber,
            'payment' => 'نوع المستند قبض البيان إيصال رقم '.$movementNumber,
            'return' => 'نوع المستند مرتجعات سيارة البيان فاتورة رقم '.$movementNumber,
            default => trim($fallback) !== '' ? trim($fallback) : $this->documentType($type).' رقم '.$movementNumber,
        };
    }
}
