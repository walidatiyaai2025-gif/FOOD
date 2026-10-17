<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseProcurementMatchReview;
use App\Models\EnterprisePurchaseInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProcurementMatchManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_invoices.view';

    private const DECIDE_PERMISSION = 'procurement.purchase_invoices.handoff';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
        private readonly AuditLogger $audit,
    ) {}

    /** @return LengthAwarePaginator<int, object> */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['pending', 'matched', 'exception', 'approved', 'rejected'])],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::table('enterprise_purchase_invoices as invoice')
            ->leftJoin('enterprise_procurement_match_reviews as review', 'review.purchase_invoice_id', '=', 'invoice.id')
            ->where('invoice.organization_id', $scope->organizationId)
            ->where('invoice.company_id', $scope->companyId)
            ->where('invoice.store_id', $scope->storeId)
            ->where('invoice.status', '!=', 'cancelled')
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->whereRaw(
                    'COALESCE(review.status, ?) = ?',
                    ['pending', $status],
                ),
            )
            ->when(
                $validated['supplier_id'] ?? null,
                fn ($query, mixed $supplierId) => $query->where('invoice.supplier_id', (int) $supplierId),
            )
            ->select([
                'invoice.id as purchase_invoice_id',
                'invoice.invoice_number',
                'invoice.supplier_invoice_number',
                'invoice.supplier_id',
                'invoice.supplier_code',
                'invoice.currency_code',
                'invoice.total_amount',
                'invoice.status as invoice_status',
                'review.id as review_id',
                DB::raw("COALESCE(review.status, 'pending') as match_status"),
                'review.quantity_variance',
                'review.receipt_variance',
                'review.price_variance',
                'review.value_variance',
                'review.updated_at as match_updated_at',
            ])
            ->orderByDesc('invoice.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array{review:EnterpriseProcurementMatchReview,invoice:EnterprisePurchaseInvoice,lines:list<array<string,mixed>>} */
    public function refresh(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
        float $tolerancePercent = 0.5,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->refreshForScope($actor, $scope, $invoiceId, $tolerancePercent);
    }

    /** @return array{review:EnterpriseProcurementMatchReview,invoice:EnterprisePurchaseInvoice,lines:list<array<string,mixed>>} */
    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $invoice = $this->visibleInvoice($scope, $invoiceId);
        $review = EnterpriseProcurementMatchReview::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->first();

        if (! $review instanceof EnterpriseProcurementMatchReview) {
            return $this->refreshForScope($actor, $scope, $invoiceId, 0.5);
        }

        return [
            'review' => $review,
            'invoice' => $invoice,
            'lines' => is_array($review->line_results) ? $review->line_results : [],
        ];
    }

    public function decide(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
        string $decision,
        ?string $note = null,
    ): EnterpriseProcurementMatchReview {
        $scope = $this->authorizedScope($actor, $context, self::DECIDE_PERMISSION);
        $decision = Validator::make(
            ['decision' => $decision, 'note' => $note],
            [
                'decision' => ['required', Rule::in(['approve', 'reject'])],
                'note' => ['nullable', 'string', 'max:2000'],
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $scope, $invoiceId, $decision): EnterpriseProcurementMatchReview {
            $current = $this->refreshForScope($actor, $scope, $invoiceId, 0.5);
            $review = EnterpriseProcurementMatchReview::query()
                ->whereKey($current['review']->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($review->status === 'matched') {
                return $review;
            }

            if (! in_array($review->status, ['exception', 'approved', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'decision' => ['Only an invoice match exception can be reviewed.'],
                ]);
            }

            $before = $review->toArray();
            $review->forceFill([
                'status' => $decision['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'decision_note' => $decision['note'] ?? null,
            ])->save();

            $this->audit->record(
                'enterprise.procurement.match_decided',
                $actor,
                $review,
                $before,
                $review->fresh()->toArray(),
            );

            return $review->refresh();
        }, 3);
    }

    public function assertInvoiceHandoffAllowed(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
    ): EnterpriseProcurementMatchReview {
        $scope = $this->authorizedScope($actor, $context, self::DECIDE_PERMISSION);
        $result = $this->refreshForScope($actor, $scope, $invoiceId, 0.5);
        $review = $result['review'];

        if (! in_array($review->status, ['matched', 'approved'], true)) {
            throw ValidationException::withMessages([
                'match' => [
                    $review->status === 'rejected'
                        ? 'The three-way match exception was rejected and the invoice cannot be handed off.'
                        : 'Resolve or approve the three-way match exception before handing this invoice to Finance.',
                ],
            ]);
        }

        return $review;
    }

    /** @return array{review:EnterpriseProcurementMatchReview,invoice:EnterprisePurchaseInvoice,lines:list<array<string,mixed>>} */
    private function refreshForScope(
        ?User $actor,
        EnterpriseDataScope $scope,
        int $invoiceId,
        float $tolerancePercent,
    ): array {
        $tolerancePercent = max(0, min(25, round($tolerancePercent, 3)));
        $invoice = $this->visibleInvoice($scope, $invoiceId);
        $sourceRows = $this->sourceRows($invoice->id);

        if ($sourceRows->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['A purchase invoice must contain source lines before three-way matching.'],
            ]);
        }

        $lines = [];
        $quantityVariance = 0.0;
        $receiptVariance = 0.0;
        $priceVariance = 0.0;
        $valueVariance = 0.0;
        $exceptionReasons = [];

        foreach ($sourceRows as $row) {
            $ordered = round((float) $row->ordered_quantity, 3);
            $received = round((float) $row->received_quantity, 3);
            $invoiced = round((float) $row->invoice_quantity, 3);
            $poPrice = round((float) $row->po_unit_price, 3);
            $invoicePrice = round((float) $row->invoice_unit_price, 3);
            $quantityDelta = round($invoiced - $received, 3);
            $receiptDelta = round($received - $ordered, 3);
            $priceDelta = round($invoicePrice - $poPrice, 3);
            $valueDelta = round(($invoicePrice - $poPrice) * $invoiced, 3);
            $quantityTolerance = max(0.001, abs($received) * ($tolerancePercent / 100));
            $priceTolerance = max(0.001, abs($poPrice) * ($tolerancePercent / 100));
            $lineExceptions = [];

            if (abs($quantityDelta) > $quantityTolerance) {
                $lineExceptions[] = 'invoice_vs_receipt_quantity';
            }
            if ($receiptDelta > $quantityTolerance) {
                $lineExceptions[] = 'receipt_vs_order_quantity';
            }
            if (abs($priceDelta) > $priceTolerance) {
                $lineExceptions[] = 'invoice_vs_order_price';
            }

            $quantityVariance += $quantityDelta;
            $receiptVariance += $receiptDelta;
            $priceVariance += $priceDelta;
            $valueVariance += $valueDelta;
            $exceptionReasons = [...$exceptionReasons, ...$lineExceptions];

            $lines[] = [
                'purchase_invoice_line_id' => (int) $row->purchase_invoice_line_id,
                'goods_receipt_line_id' => (int) $row->goods_receipt_line_id,
                'purchase_order_line_id' => (int) $row->purchase_order_line_id,
                'line_no' => (int) $row->line_no,
                'description' => (string) $row->description,
                'ordered_quantity' => $ordered,
                'received_quantity' => $received,
                'invoice_quantity' => $invoiced,
                'po_unit_price' => $poPrice,
                'invoice_unit_price' => $invoicePrice,
                'quantity_variance' => $quantityDelta,
                'receipt_variance' => $receiptDelta,
                'price_variance' => $priceDelta,
                'value_variance' => $valueDelta,
                'exceptions' => array_values(array_unique($lineExceptions)),
            ];
        }

        $fingerprint = hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $status = $exceptionReasons === [] ? 'matched' : 'exception';
        $reason = $exceptionReasons === []
            ? null
            : implode(', ', array_values(array_unique($exceptionReasons)));

        $review = DB::transaction(function () use (
            $actor,
            $invoice,
            $tolerancePercent,
            $quantityVariance,
            $receiptVariance,
            $priceVariance,
            $valueVariance,
            $fingerprint,
            $status,
            $reason,
            $lines,
        ): EnterpriseProcurementMatchReview {
            $review = EnterpriseProcurementMatchReview::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (
                $review instanceof EnterpriseProcurementMatchReview
                && hash_equals((string) $review->source_fingerprint, $fingerprint)
                && in_array($review->status, ['approved', 'rejected'], true)
            ) {
                return $review;
            }

            $before = $review?->toArray();
            $payload = [
                'source_fingerprint' => $fingerprint,
                'status' => $status,
                'tolerance_percent' => $tolerancePercent,
                'quantity_variance' => round($quantityVariance, 3),
                'receipt_variance' => round($receiptVariance, 3),
                'price_variance' => round($priceVariance, 3),
                'value_variance' => round($valueVariance, 3),
                'line_results' => $lines,
                'exception_reason' => $reason,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'decision_note' => null,
            ];

            if ($review instanceof EnterpriseProcurementMatchReview) {
                $review->forceFill($payload)->save();
            } else {
                $review = EnterpriseProcurementMatchReview::query()->create([
                    'purchase_invoice_id' => $invoice->id,
                    ...$payload,
                ]);
            }

            $this->audit->record(
                'enterprise.procurement.match_refreshed',
                $actor,
                $review,
                $before,
                $review->fresh()->toArray(),
            );

            return $review->refresh();
        }, 3);

        return ['review' => $review, 'invoice' => $invoice, 'lines' => $lines];
    }

    private function visibleInvoice(EnterpriseDataScope $scope, int $invoiceId): EnterprisePurchaseInvoice
    {
        return EnterprisePurchaseInvoice::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($invoiceId)
            ->firstOrFail();
    }

    /** @return Collection<int, object> */
    private function sourceRows(int $invoiceId): Collection
    {
        return DB::table('enterprise_purchase_invoice_lines as invoice_line')
            ->join(
                'enterprise_goods_receipt_lines as receipt_line',
                'receipt_line.id',
                '=',
                'invoice_line.goods_receipt_line_id',
            )
            ->join(
                'enterprise_purchase_order_lines as order_line',
                'order_line.id',
                '=',
                'invoice_line.purchase_order_line_id',
            )
            ->where('invoice_line.purchase_invoice_id', $invoiceId)
            ->orderBy('invoice_line.line_no')
            ->get([
                'invoice_line.id as purchase_invoice_line_id',
                'invoice_line.goods_receipt_line_id',
                'invoice_line.purchase_order_line_id',
                'invoice_line.line_no',
                'invoice_line.description',
                'order_line.quantity as ordered_quantity',
                'receipt_line.received_quantity',
                'invoice_line.invoice_quantity',
                'order_line.unit_price as po_unit_price',
                'invoice_line.unit_price as invoice_unit_price',
            ]);
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
}
