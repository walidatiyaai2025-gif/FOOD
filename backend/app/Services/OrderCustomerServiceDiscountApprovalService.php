<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderCustomerServiceDiscountApprovalService
{
    public function __construct(
        private readonly OrderCustomerServiceDiscountService $discounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A request is not a pricing mutation. The authoritative order is locked
     * again at review; stale paid/dispatched/taxed orders are rejected then.
     */
    public function request(
        Order $order,
        User $actor,
        float $percentage,
        string $reason,
        string $idempotencyKey,
        Request $request,
    ): bool {
        return DB::transaction(function () use ($order, $actor, $percentage, $reason, $idempotencyKey, $request): bool {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (DB::table('order_customer_service_discount_requests')
                ->where('order_id', $locked->getKey())
                ->where('idempotency_key', $idempotencyKey)->exists()) {
                return false;
            }

            if (strtolower((string) $locked->status) !== 'pending') {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            DB::table('order_customer_service_discount_requests')->insert([
                'order_id' => $locked->getKey(),
                'requested_by_user_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'percentage' => $percentage,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('order.customer_service_discount.requested', $actor, $locked, [], [
                'store_id' => $locked->store_id,
                'percentage' => $percentage,
                'reason' => $reason,
                'status' => 'pending',
            ], $request);

            return true;
        }, 3);
    }

    /**
     * Only an independently authorized reviewer may transition a pending
     * request. Both the approval and the real financial change are atomic.
     * Rejection never changes order pricing or notifies the customer.
     */
    public function review(
        Order $order,
        User $reviewer,
        int $requestId,
        bool $approved,
        ?string $reviewReason,
        Request $request,
    ): void {
        DB::transaction(function () use ($order, $reviewer, $requestId, $approved, $reviewReason, $request): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $record = DB::table('order_customer_service_discount_requests')
                ->where('id', $requestId)
                ->where('order_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            abort_if($record === null, 404);
            abort_if((string) $record->status !== 'pending', 409);
            abort_if((int) $record->requested_by_user_id === (int) $reviewer->getKey(), 403);

            if ($approved) {
                $applied = $this->discounts->apply(
                    $locked,
                    $reviewer,
                    (float) $record->percentage,
                    (string) $record->reason,
                    (string) $record->idempotency_key,
                    $request,
                );

                // Idempotency keys must not authorize a second approval.
                abort_unless($applied, 409);
            }

            $nextStatus = $approved ? 'approved' : 'rejected';
            DB::table('order_customer_service_discount_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->update([
                    'status' => $nextStatus,
                    'reviewed_by_user_id' => $reviewer->getKey(),
                    'review_reason' => $reviewReason,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->audit->record('order.customer_service_discount.'.$nextStatus, $reviewer, $locked, [
                'request_id' => $requestId,
                'status' => 'pending',
            ], [
                'request_id' => $requestId,
                'status' => $nextStatus,
                'requested_by_user_id' => $record->requested_by_user_id,
                'percentage' => $record->percentage,
                'review_reason' => $reviewReason,
            ], $request);
        }, 3);
    }
}
