<?php

namespace App\Services;

use App\Models\DeliveryProof;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DriverOrderService
{
    public function __construct(
        private readonly DashboardOperationalNotifier $notifier,
        private readonly DeliveryExecutionService $execution,
        private readonly DeliverySettlementService $settlements,
    ) {}

    /** @return list<string> */
    public function availableStatuses(DriverAssignment $assignment, Order $order): array
    {
        return $this->execution->availableStatuses(
            (string) $assignment->status,
            $order,
            $assignment->completed_at !== null,
        );
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
                'order_items.selling_unit_code_snapshot',
                'order_items.selling_unit_name_snapshot',
                'order_items.selling_unit_quantity',
                'order_items.base_quantity',
                'order_items.conversion_factor_snapshot',
                'order_items.selling_unit_sku_snapshot',
                'order_items.selling_unit_barcode_snapshot',
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
                $unitName = trim((string) ($item->selling_unit_name_snapshot ?? ''));
                $unitCode = trim((string) ($item->selling_unit_code_snapshot ?? ''));
                if ($unitName === '') {
                    $unitName = trim((string) ($item->unit_name ?? ''));
                }
                if ($unitCode === '') {
                    $unitCode = trim((string) ($item->unit_code ?? ''));
                }
                $unit = $unitName !== '' ? $unitName : $unitCode;
                $sellingQuantity = $item->selling_unit_quantity === null
                    ? (float) $item->quantity
                    : (float) $item->selling_unit_quantity;
                $baseQuantity = $item->base_quantity === null
                    ? (float) $item->quantity
                    : (float) $item->base_quantity;
                $commercialConversion = $item->conversion_factor_snapshot === null
                    ? $conversionFactor
                    : (float) $item->conversion_factor_snapshot;

                return [
                    'product_id' => (int) $item->product_id,
                    'sku' => (string) $item->sku_snapshot,
                    'name' => (string) $item->name_snapshot,
                    'image_url' => $imageUrl,
                    'variant' => null,
                    'quantity' => $sellingQuantity,
                    'base_quantity' => $baseQuantity,
                    'quantity_conversion_factor' => $commercialConversion,
                    'selling_unit_code' => $unitCode !== '' ? $unitCode : null,
                    'selling_unit_name' => $unitName !== '' ? $unitName : null,
                    'selling_unit_sku' => $item->selling_unit_sku_snapshot,
                    'selling_unit_barcode' => $item->selling_unit_barcode_snapshot,
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
        $settlement = $this->settlements->summarize($order, $invoiceModel, $payment);

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
        $normalizedNote = $this->execution->normalizeNote($note);
        $normalizedFailureReason = $this->execution->normalizeFailureReason($targetStatus, $failureReason);
        $idempotencyKey = $this->execution->normalizeIdempotencyKey($idempotencyKey);
        $requestFingerprint = $idempotencyKey === null
            ? null
            : $this->execution->transitionFingerprint(
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
                        $this->execution->assertReplayMatches(
                            (string) $prior->to_status,
                            $prior->request_fingerprint,
                            $targetStatus,
                            $requestFingerprint,
                        );

                        $replayed = true;

                        return $locked;
                    }
                }

                $this->execution->assertTransitionAllowed(
                    (string) $locked->status,
                    $order,
                    $targetStatus,
                    $locked->completed_at !== null,
                );

                $this->execution->assertTransitionRequirements(
                    $order,
                    $targetStatus,
                    $normalizedNote,
                    $normalizedFailureReason,
                    $proofImage,
                );

                $orderSync = $this->execution->synchronizeOrder(
                    $order,
                    $actor,
                    $beforeAssignment,
                    $targetStatus,
                    $normalizedNote,
                    $normalizedFailureReason,
                    $request,
                    'driver',
                );
                $beforeOrder = $orderSync['before'];
                $afterOrder = $orderSync['after'];

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

                $this->execution->recordTransitionAudit(
                    $locked,
                    $actor,
                    $beforeAssignment,
                    $targetStatus,
                    $normalizedFailureReason,
                    $normalizedNote,
                    $idempotencyKey,
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
}
