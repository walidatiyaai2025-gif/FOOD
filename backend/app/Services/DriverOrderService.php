<?php

namespace App\Services;

use App\Models\DeliveryProof;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DriverOrderService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
        private readonly OrderInventoryReservationService $reservations,
        private readonly OperationalLookupService $lookups,
    ) {}

    /** @return list<string> */
    public function availableStatuses(DriverAssignment $assignment, Order $order): array
    {
        $assignmentStatus = (string) $assignment->status;
        $orderStatus = (string) $order->status;

        if (
            $assignment->completed_at !== null
            || in_array(
                $assignmentStatus,
                ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'],
                true,
            )
            || in_array($orderStatus, ['cancelled', 'delivered'], true)
        ) {
            return [];
        }

        return match ($assignmentStatus) {
            'assigned' => ['accepted'],
            'accepted' => ['picked_up', 'failed'],
            'picked_up' => ['out_for_delivery', 'failed'],
            'out_for_delivery' => $orderStatus === 'out_for_delivery' ? ['delivered', 'failed'] : [],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function payload(DriverAssignment $assignment): array
    {
        $order = Order::query()->findOrFail($assignment->order_id);
        abort_unless(
            (int) $order->store_id === (int) $assignment->store_id
                && strtolower((string) $order->channel) === strtolower((string) $assignment->assignment_type),
            404,
        );

        $channel = strtolower((string) $assignment->assignment_type);
        $customerTable = $channel === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $domainCustomerId = $order->getAttribute($customerColumn);

        $customer = $domainCustomerId === null
            ? null
            : DB::table($customerTable)
                ->where('id', $domainCustomerId)
                ->first(['name', 'phone', 'email']);

        // Delivery execution must use the order snapshot, never the mutable
        // customer address row. This keeps navigation/history stable after profile edits.
        $address = app(OrderDeliveryAddressSnapshotService::class)->payload($order);

        $payment = DB::table('payments')
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->first(['provider', 'status', 'amount', 'currency', 'metadata']);

        $store = DB::table('stores')
            ->where('id', $order->store_id)
            ->first(['name', 'code']);

        $items = DB::table('order_items')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('order_items.order_id', $order->getKey())
            ->orderBy('order_items.id')
            ->get([
                'order_items.product_id',
                'order_items.sku_snapshot',
                'order_items.name_snapshot',
                'order_items.quantity',
                'order_items.quantity_conversion_factor',
                'order_items.pack_size_snapshot',
                'order_items.case_size_snapshot',
                'order_items.unit_price',
                'order_items.line_total',
                'units.code as unit_code',
                'units.name as unit_name',
                DB::raw('(select path from product_images where product_images.product_id = order_items.product_id order by is_primary desc, sort_order asc, id asc limit 1) as image_path'),
            ])
            ->map(static function (object $item): array {
                $imagePath = trim((string) ($item->image_path ?? ''));
                $imageUrl = $imagePath === ''
                    ? null
                    : (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')
                        ? $imagePath
                        : url('/'.ltrim($imagePath, '/')));
                $conversionFactor = (float) ($item->quantity_conversion_factor ?? 1);
                $packSize = $item->pack_size_snapshot === null ? null : (float) $item->pack_size_snapshot;
                $caseSize = $item->case_size_snapshot === null ? null : (float) $item->case_size_snapshot;
                $unitName = trim((string) ($item->unit_name ?? ''));
                $unitCode = trim((string) ($item->unit_code ?? ''));
                $unit = $unitName !== '' ? $unitName : $unitCode;

                return [
                    'product_id' => (int) $item->product_id,
                    'sku' => (string) $item->sku_snapshot,
                    'name' => (string) $item->name_snapshot,
                    'image_url' => $imageUrl,
                    'variant' => null,
                    'quantity' => (float) $item->quantity,
                    'quantity_conversion_factor' => $conversionFactor,
                    'pack_size' => $packSize,
                    'case_size' => $caseSize,
                    'unit' => $unit,
                    'note' => null,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                ];
            })
            ->values()
            ->all();

        $invoice = DB::table('invoices')
            ->where('order_id', $order->getKey())
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->first([
                'id',
                'invoice_number',
                'revision',
                'status',
                'currency',
                'subtotal',
                'discount_total',
                'delivery_total',
                'tax_total',
                'total',
                'payment_method_snapshot',
                'payment_status_snapshot',
                'issued_at',
            ]);

        $invoiceItems = $invoice === null
            ? []
            : DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->get([
                    'sku_snapshot',
                    'description',
                    'quantity',
                    'unit_price',
                    'line_discount_total',
                    'line_tax_total',
                    'line_total',
                    'currency',
                ])
                ->map(static fn (object $item): array => [
                    'sku' => $item->sku_snapshot,
                    'name' => (string) $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_total' => (float) ($item->line_discount_total ?? 0),
                    'tax_total' => (float) ($item->line_tax_total ?? 0),
                    'line_total' => (float) $item->line_total,
                    'currency' => (string) ($item->currency ?: $order->currency),
                ])
                ->values()
                ->all();

        $invoiceModel = $invoice === null ? null : Invoice::query()->find((int) $invoice->id);
        $settlement = $this->settlement($order, $invoiceModel, $payment);

        $driverHistory = DB::table('delivery_proofs')
            ->leftJoin('users', 'users.id', '=', 'delivery_proofs.user_id')
            ->where('driver_assignment_id', $assignment->getKey())
            ->whereIn('proof_type', ['status_note', 'failure_note', 'failure_image', 'delivery_image'])
            ->orderByDesc('delivery_proofs.id')
            ->limit(50)
            ->get([
                'delivery_proofs.from_status',
                'delivery_proofs.to_status',
                'delivery_proofs.proof_type',
                'delivery_proofs.file_path',
                'delivery_proofs.reason_code',
                'delivery_proofs.note',
                'delivery_proofs.captured_at',
                'users.name as actor_name',
            ])
            ->map(static fn (object $event): array => [
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'proof_type' => $event->proof_type,
                'file_path' => $event->file_path,
                'reason_code' => $event->reason_code,
                'note' => $event->note,
                'actor_name' => $event->actor_name,
                'captured_at' => $event->captured_at === null ? null : (string) $event->captured_at,
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $assignment->getKey(),
            'driver_id' => (int) $assignment->driver_id,
            'order_id' => (int) $order->getKey(),
            'store_id' => (int) $order->store_id,
            'assignment_type' => $channel,
            'status' => (string) $assignment->status,
            'assigned_at' => $assignment->assigned_at,
            'completed_at' => $assignment->completed_at,
            'available_statuses' => $this->availableStatuses($assignment, $order),
            'order' => [
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'subtotal' => (float) $order->subtotal,
                'discount_total' => (float) $order->discount_total,
                'delivery_total' => (float) $order->delivery_total,
                'grand_total' => (float) $order->grand_total,
                'payment_method' => (string) ($order->payment_method ?? ''),
                'customer_note' => $order->customer_note,
                'created_at' => $order->created_at,
                'store' => [
                    'name' => $store?->name,
                    'code' => $store?->code,
                ],
                'customer' => [
                    'name' => $customer?->name,
                    'phone' => $customer?->phone,
                    'email' => $customer?->email,
                ],
                'address' => $address,
                'navigation' => $address === null ? [
                    'available' => false,
                    'latitude' => null,
                    'longitude' => null,
                ] : [
                    'available' => (bool) ($address['has_coordinates'] ?? false),
                    'latitude' => $address['latitude'] ?? null,
                    'longitude' => $address['longitude'] ?? null,
                ],
                'payment' => $payment === null ? null : [
                    'provider' => $payment->provider,
                    'status' => $payment->status,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                ],
                'settlement' => $settlement,
                'items' => $items,
                'invoice' => $invoice === null ? null : [
                    'id' => (int) $invoice->id,
                    'number' => (string) $invoice->invoice_number,
                    'revision' => (int) ($invoice->revision ?? 1),
                    'status' => (string) $invoice->status,
                    'currency' => (string) $invoice->currency,
                    'subtotal' => (float) ($invoice->subtotal ?? 0),
                    'discount_total' => (float) ($invoice->discount_total ?? 0),
                    'delivery_total' => (float) ($invoice->delivery_total ?? 0),
                    'tax_total' => (float) ($invoice->tax_total ?? 0),
                    'grand_total' => (float) $invoice->total,
                    'payment_method' => (string) ($payment->provider ?? $invoice->payment_method_snapshot ?? ''),
                    'payment_status' => (string) ($payment->status ?? $invoice->payment_status_snapshot ?? ''),
                    'outstanding_amount' => (float) $settlement['invoice_outstanding_amount'],
                    'download_path' => '/api/v1/driver/assignments/'.(int) $assignment->getKey().'/invoice/download',
                    'issued_at' => $invoice->issued_at === null ? null : (string) $invoice->issued_at,
                    'items' => $invoiceItems,
                ],
                'driver_history' => $driverHistory,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function settlement(Order $order, ?Invoice $invoice, ?object $payment): array
    {
        $metadataRaw = $payment?->metadata;
        $metadata = is_array($metadataRaw)
            ? $metadataRaw
            : (is_string($metadataRaw) ? json_decode($metadataRaw, true) : []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $balanceApplied = 0.0;
        foreach (['customer_balance_applied', 'balance_applied', 'applied_balance_amount'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $balanceApplied = round(max(0, (float) $metadata[$key]), 3);
                break;
            }
        }

        $contractRemaining = null;
        foreach (['remaining_amount', 'remainder_amount', 'amount_after_balance'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $contractRemaining = round(max(0, (float) $metadata[$key]), 3);
                break;
            }
        }
        if ($contractRemaining === null && $balanceApplied > 0.0001) {
            $afterBalance = round(max((float) $order->grand_total - $balanceApplied, 0), 3);
            $paymentAmount = $payment === null ? $afterBalance : round(max(0, (float) $payment->amount), 3);
            $contractRemaining = min($afterBalance, $paymentAmount);
        }

        $paidAmount = 0.0;
        $outstanding = round(max(0, (float) $order->grand_total), 3);
        if ($invoice instanceof Invoice) {
            if (strtolower((string) $invoice->channel) === 'b2b') {
                $amounts = app(B2bAccountLedgerService::class)->invoiceAmounts($invoice);
                $paidAmount = round((float) $amounts['paid_amount'], 3);
                $outstanding = round((float) $amounts['outstanding_amount'], 3);
            } else {
                $paidAmount = round((float) DB::table('payments')
                    ->where('invoice_id', $invoice->getKey())
                    ->where('status', 'paid')
                    ->sum('amount'), 3);
                $outstanding = round(max((float) $invoice->total - $paidAmount, 0), 3);
            }
        } elseif ($payment !== null && (string) $payment->status === 'paid') {
            $paidAmount = round((float) $payment->amount, 3);
            $outstanding = round(max((float) $order->grand_total - $paidAmount, 0), 3);
        }

        if ($contractRemaining !== null) {
            $outstanding = min($outstanding, $contractRemaining);
            if (
                $payment !== null
                && (string) $payment->status === 'paid'
                && (float) $payment->amount + 0.0001 >= $contractRemaining
            ) {
                $outstanding = 0.0;
            }
        }

        $rawRemainderMethod = strtolower(trim((string) (
            $metadata['remainder_method']
            ?? $payment?->provider
            ?? $order->payment_method
            ?? ''
        )));
        $remainderMethod = match ($rawRemainderMethod) {
            'cash_on_delivery', 'cod' => 'cash_on_delivery',
            'account_debt', 'account_credit', 'account' => 'account_debt',
            default => $rawRemainderMethod,
        };

        $paymentState = $outstanding <= 0.0001
            ? 'fully_settled'
            : (($paidAmount > 0.0001 || $balanceApplied > 0.0001)
                ? 'partially_settled'
                : 'unpaid');
        $collectNow = $paymentState !== 'fully_settled' && $remainderMethod === 'cash_on_delivery'
            ? $outstanding
            : 0.0;

        return [
            'currency' => (string) ($invoice?->currency ?? $order->currency),
            'order_total' => round((float) $order->grand_total, 3),
            'balance_applied' => $balanceApplied,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $outstanding,
            'remainder_method' => $remainderMethod,
            'payment_state' => $paymentState,
            'amount_to_collect_now' => round($collectNow, 3),
            'invoice_outstanding_amount' => $outstanding,
        ];
    }

    /** @return array<string, mixed> */
    public function historyPayload(DriverAssignment $assignment): array
    {
        $order = Order::query()->findOrFail($assignment->order_id);
        abort_unless(
            (int) $order->store_id === (int) $assignment->store_id
                && strtolower((string) $order->channel) === strtolower((string) $assignment->assignment_type),
            404,
        );

        return [
            'id' => (int) $assignment->getKey(),
            'driver_id' => (int) $assignment->driver_id,
            'order_id' => (int) $order->getKey(),
            'store_id' => (int) $order->store_id,
            'assignment_type' => strtolower((string) $assignment->assignment_type),
            'status' => (string) $assignment->status,
            'assigned_at' => $assignment->assigned_at,
            'completed_at' => $assignment->completed_at,
            'available_statuses' => [],
            'order' => [
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'created_at' => $order->created_at,
            ],
        ];
    }

    public function transition(
        DriverAssignment $assignment,
        Driver $driver,
        User $actor,
        string $targetStatus,
        ?string $note,
        Request $request,
        ?UploadedFile $proofImage = null,
        ?string $failureReason = null,
        ?string $idempotencyKey = null,
    ): DriverAssignment {
        $beforeAssignment = (string) $assignment->status;
        $beforeOrder = null;
        $afterOrder = null;
        $normalizedNote = trim((string) $note);
        $normalizedNote = $normalizedNote === '' ? null : $normalizedNote;
        $normalizedFailureReason = $targetStatus === 'failed'
            ? trim((string) $failureReason)
            : null;
        $idempotencyKey = trim((string) $idempotencyKey);
        $idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;
        $requestFingerprint = $idempotencyKey === null
            ? null
            : $this->transitionFingerprint(
                $targetStatus,
                $normalizedNote,
                $normalizedFailureReason,
                $proofImage,
            );
        $proofPath = null;
        $replayed = false;

        try {
            $updated = DB::transaction(function () use (
                $assignment,
                $driver,
                $actor,
                $targetStatus,
                $normalizedNote,
                $request,
                $proofImage,
                $normalizedFailureReason,
                $idempotencyKey,
                $requestFingerprint,
                &$beforeAssignment,
                &$beforeOrder,
                &$afterOrder,
                &$proofPath,
                &$replayed,
            ): DriverAssignment {
                $locked = DriverAssignment::query()
                    ->whereKey($assignment->getKey())
                    ->where('driver_id', $driver->getKey())
                    ->where('assignment_type', strtolower((string) $driver->driver_type))
                    ->lockForUpdate()
                    ->firstOrFail();

                $beforeAssignment = (string) $locked->status;
                $order = Order::query()
                    ->whereKey($locked->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    (int) $order->store_id === (int) $locked->store_id
                        && (int) $order->store_id === (int) $driver->store_id
                        && strtolower((string) $order->channel) === strtolower((string) $locked->assignment_type),
                    404,
                );

                if ($idempotencyKey !== null) {
                    $prior = DeliveryProof::query()
                        ->where('driver_assignment_id', $locked->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($prior instanceof DeliveryProof) {
                        abort_unless(
                            (string) $prior->to_status === $targetStatus
                                && is_string($prior->request_fingerprint)
                                && is_string($requestFingerprint)
                                && hash_equals($prior->request_fingerprint, $requestFingerprint),
                            409,
                            'Idempotency-Key was already used for a different delivery transition request.',
                        );

                        $replayed = true;

                        return $locked;
                    }
                }

                $allowed = $this->availableStatuses($locked, $order);
                abort_unless(
                    in_array($targetStatus, $allowed, true),
                    409,
                    'The delivery action is not available for the current order state.',
                );

                if ($targetStatus === 'delivered') {
                    if (! $proofImage instanceof UploadedFile || ! $proofImage->isValid()) {
                        throw ValidationException::withMessages([
                            'proof_image' => ['A valid delivery proof image is required before completing delivery.'],
                        ]);
                    }
                }

                if ($targetStatus === 'failed') {
                    if (
                        $normalizedFailureReason === null
                        || ! in_array(
                            $normalizedFailureReason,
                            $this->lookups->activeCodes(OperationalLookupService::FAILED_DELIVERY_REASON),
                            true,
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'failure_reason' => ['A valid failure reason is required for failed delivery.'],
                        ]);
                    }

                    if ($normalizedFailureReason === 'other' && $normalizedNote === null) {
                        throw ValidationException::withMessages([
                            'note' => ['A note is required when the failure reason is other.'],
                        ]);
                    }
                }

                if ($proofImage !== null && ! $proofImage->isValid()) {
                    throw ValidationException::withMessages([
                        'proof_image' => ['The delivery proof image could not be read.'],
                    ]);
                }

                $beforeOrder = (string) $order->status;

                if ($targetStatus === 'out_for_delivery') {
                    abort_unless(
                        ! in_array((string) $order->status, ['cancelled', 'delivered', 'failed'], true),
                        409,
                    );
                    $this->updateOrderStatus(
                        $order,
                        $actor,
                        'out_for_delivery',
                        $normalizedNote,
                        $request,
                    );
                    $afterOrder = 'out_for_delivery';
                } elseif ($targetStatus === 'delivered') {
                    abort_unless((string) $order->status === 'out_for_delivery', 409);
                    $this->reservations->consume($order, $actor);
                    $this->updateOrderStatus(
                        $order,
                        $actor,
                        'delivered',
                        $normalizedNote,
                        $request,
                    );
                    $afterOrder = 'delivered';
                } elseif ($targetStatus === 'failed') {
                    abort_unless(
                        ! in_array((string) $order->status, ['cancelled', 'delivered', 'failed'], true),
                        409,
                    );
                    $failureAuditNote = $normalizedFailureReason
                        .($normalizedNote !== null ? ': '.$normalizedNote : '');
                    $this->updateOrderStatus(
                        $order,
                        $actor,
                        'failed',
                        $failureAuditNote,
                        $request,
                    );
                    $afterOrder = 'failed';
                } elseif ($targetStatus === 'picked_up') {
                    abort_unless(
                        ! in_array((string) $order->status, ['cancelled', 'delivered', 'failed'], true),
                        409,
                    );
                }

                $locked->status = $targetStatus;
                $locked->completed_at = in_array($targetStatus, ['delivered', 'failed'], true)
                    ? now()
                    : null;
                $locked->save();

                if ($proofImage !== null && in_array($targetStatus, ['delivered', 'failed'], true)) {
                    $storedPath = $proofImage->store('delivery-proofs', 'public');
                    if (! is_string($storedPath) || $storedPath === '') {
                        throw ValidationException::withMessages([
                            'proof_image' => ['The delivery proof image could not be stored.'],
                        ]);
                    }
                    $proofPath = $storedPath;
                }

                $proofType = match (true) {
                    $targetStatus === 'delivered' => 'delivery_image',
                    $targetStatus === 'failed' && $proofPath !== null => 'failure_image',
                    $targetStatus === 'failed' => 'failure_note',
                    default => 'status_note',
                };

                DeliveryProof::query()->create([
                    'driver_assignment_id' => $locked->getKey(),
                    'order_id' => $order->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                    'user_id' => $actor->getKey(),
                    'proof_type' => $proofType,
                    'from_status' => $beforeAssignment,
                    'to_status' => $targetStatus,
                    'file_path' => $proofPath,
                    'otp_hash' => null,
                    'reason_code' => $targetStatus === 'failed'
                        ? $normalizedFailureReason
                        : null,
                    'note' => $normalizedNote,
                    'captured_at' => now(),
                ]);

                $this->audit->record(
                    'delivery.assignment.status_changed',
                    $actor,
                    $locked,
                    ['status' => $beforeAssignment],
                    [
                        'status' => $targetStatus,
                        'failure_reason' => $normalizedFailureReason,
                        'note' => $normalizedNote,
                        'idempotency_key' => $idempotencyKey,
                    ],
                    $request,
                );

                return $locked;
            }, 3);
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        $fresh = $updated->fresh();
        if ($replayed) {
            return $fresh;
        }

        $order = Order::query()->findOrFail($fresh->order_id);
        if ($beforeOrder !== null && $afterOrder !== null && $beforeOrder !== $afterOrder) {
            $this->notifier->orderStatusChanged($order, $beforeOrder, $afterOrder);
        } else {
            $this->notifier->deliveryChanged(
                $order,
                (string) $fresh->status,
                $fresh,
                $normalizedNote,
                $beforeAssignment,
            );
        }

        return $fresh;
    }

    private function transitionFingerprint(
        string $targetStatus,
        ?string $note,
        ?string $failureReason,
        ?UploadedFile $proofImage,
    ): string {
        $proofHash = null;
        if ($proofImage instanceof UploadedFile && $proofImage->isValid()) {
            $realPath = $proofImage->getRealPath();
            if (is_string($realPath) && $realPath !== '') {
                $hash = hash_file('sha256', $realPath);
                $proofHash = $hash === false ? null : $hash;
            }
        }

        return hash('sha256', json_encode([
            'status' => $targetStatus,
            'failure_reason' => $failureReason,
            'note' => $note,
            'proof_sha256' => $proofHash,
        ], JSON_THROW_ON_ERROR));
    }

    private function updateOrderStatus(
        Order $order,
        User $actor,
        string $status,
        ?string $note,
        Request $request,
    ): void {
        $from = (string) $order->status;
        $order->status = $status;
        $order->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'store_id' => (int) $order->store_id,
            'user_id' => $actor->getKey(),
            'from_status' => $from,
            'to_status' => $status,
            'note' => $note,
        ]);

        $this->audit->record(
            'order.status_changed',
            $actor,
            $order,
            ['status' => $from],
            ['status' => $status, 'source' => 'driver', 'note' => $note],
            $request,
        );
    }
}
