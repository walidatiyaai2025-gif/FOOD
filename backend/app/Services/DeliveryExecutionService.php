<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeliveryExecutionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderInventoryReservationService $reservations,
        private readonly OperationalLookupService $lookups,
        private readonly DeliverySettlementService $settlements,
    ) {}

    /** @return list<string> */
    public function availableStatuses(string $executionStatus, Order $order, bool $completed = false): array
    {
        $status = strtolower(trim($executionStatus));
        $orderStatus = strtolower((string) $order->status);

        if (
            $completed
            || in_array($status, ['delivered', 'cancelled', 'unassigned', 'reassigned'], true)
            || in_array($orderStatus, ['cancelled', 'delivered'], true)
        ) {
            return [];
        }

        return match ($status) {
            'assigned' => ['accepted'],
            'accepted' => ['picked_up', 'failed'],
            'picked_up' => ['out_for_delivery', 'failed'],
            'out_for_delivery' => $orderStatus === 'out_for_delivery' ? ['delivered', 'failed'] : [],
            'failed' => $orderStatus === 'failed' ? ['out_for_delivery'] : [],
            default => [],
        };
    }

    public function normalizeNote(?string $note): ?string
    {
        $normalized = trim((string) $note);

        return $normalized === '' ? null : $normalized;
    }

    public function normalizeFailureReason(string $targetStatus, ?string $failureReason): ?string
    {
        if ($targetStatus !== 'failed') {
            return null;
        }

        $normalized = trim((string) $failureReason);

        return $normalized === '' ? null : $normalized;
    }

    public function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        $normalized = trim((string) $idempotencyKey);

        return $normalized === '' ? null : $normalized;
    }

    public function transitionFingerprint(
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

    public function assertReplayMatches(
        string $priorTargetStatus,
        ?string $priorFingerprint,
        string $targetStatus,
        ?string $requestFingerprint,
    ): void {
        abort_unless(
            $priorTargetStatus === $targetStatus
                && is_string($priorFingerprint)
                && is_string($requestFingerprint)
                && hash_equals($priorFingerprint, $requestFingerprint),
            409,
            'Idempotency-Key was already used for a different delivery transition request.',
        );
    }

    public function assertTransitionAllowed(
        string $executionStatus,
        Order $order,
        string $targetStatus,
        bool $completed = false,
    ): void {
        abort_unless(
            in_array($targetStatus, $this->availableStatuses($executionStatus, $order, $completed), true),
            409,
            'The delivery action is not available for the current order state.',
        );
    }

    public function assertTransitionRequirements(
        Order $order,
        string $targetStatus,
        ?string $note,
        ?string $failureReason,
        ?UploadedFile $proofImage,
    ): void {
        if ($targetStatus === 'delivered') {
            $deliveryInvoice = Invoice::query()
                ->where('order_id', $order->getKey())
                ->whereIn('status', ['issued', 'reissued'])
                ->orderByDesc('revision')
                ->orderByDesc('id')
                ->first();
            $latestPayment = DB::table('payments')
                ->where('order_id', $order->getKey())
                ->orderByDesc('id')
                ->first(['provider', 'status', 'amount', 'currency', 'metadata']);
            $deliverySettlement = $this->settlements->summarize($order, $deliveryInvoice, $latestPayment);

            abort_if(
                (float) $deliverySettlement['amount_to_collect_now'] > 0.0001,
                409,
                'Required collection must be completed before delivery can be finalized.',
            );

            if (! $proofImage instanceof UploadedFile || ! $proofImage->isValid()) {
                throw ValidationException::withMessages([
                    'proof_image' => ['A valid delivery proof image is required before completing delivery.'],
                ]);
            }
        }

        if ($targetStatus === 'failed') {
            if (
                $failureReason === null
                || ! in_array(
                    $failureReason,
                    $this->lookups->activeCodes(OperationalLookupService::FAILED_DELIVERY_REASON),
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'failure_reason' => ['A valid failure reason is required for failed delivery.'],
                ]);
            }

            if ($failureReason === 'other' && $note === null) {
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
    }

    /** @return array{before: string, after: ?string} */
    public function synchronizeOrder(
        Order $order,
        User $actor,
        string $fromExecutionStatus,
        string $targetStatus,
        ?string $note,
        ?string $failureReason,
        Request $request,
        string $source,
    ): array {
        $before = (string) $order->status;
        $after = null;

        if ($targetStatus === 'out_for_delivery') {
            abort_unless(
                ! in_array((string) $order->status, ['cancelled', 'delivered'], true)
                    && (
                        (string) $order->status !== 'failed'
                        || $fromExecutionStatus === 'failed'
                    ),
                409,
            );
            $this->updateOrderStatus($order, $actor, 'out_for_delivery', $note, $request, $source);
            $after = 'out_for_delivery';
        } elseif ($targetStatus === 'delivered') {
            abort_unless((string) $order->status === 'out_for_delivery', 409);
            $this->reservations->consume($order, $actor);
            $this->updateOrderStatus($order, $actor, 'delivered', $note, $request, $source);
            $after = 'delivered';
        } elseif ($targetStatus === 'failed') {
            abort_unless(
                ! in_array((string) $order->status, ['cancelled', 'delivered', 'failed'], true),
                409,
            );
            $failureAuditNote = (string) $failureReason.($note !== null ? ': '.$note : '');
            $this->updateOrderStatus($order, $actor, 'failed', $failureAuditNote, $request, $source);
            $after = 'failed';
        } elseif ($targetStatus === 'picked_up') {
            abort_unless(
                ! in_array((string) $order->status, ['cancelled', 'delivered', 'failed'], true),
                409,
            );
        }

        return ['before' => $before, 'after' => $after];
    }

    public function recordTransitionAudit(
        Model $execution,
        User $actor,
        string $beforeStatus,
        string $targetStatus,
        ?string $failureReason,
        ?string $note,
        ?string $idempotencyKey,
        Request $request,
        string $event = 'delivery.assignment.status_changed',
    ): void {
        $this->audit->record(
            $event,
            $actor,
            $execution,
            ['status' => $beforeStatus],
            [
                'status' => $targetStatus,
                'failure_reason' => $failureReason,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
            ],
            $request,
        );
    }

    private function updateOrderStatus(
        Order $order,
        User $actor,
        string $status,
        ?string $note,
        Request $request,
        string $source,
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
            ['status' => $status, 'source' => $source, 'note' => $note],
            $request,
        );
    }
}
