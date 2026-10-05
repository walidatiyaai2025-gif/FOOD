<?php

namespace App\Services;

use App\Models\Order;
use App\Models\VanNoOrderReason;
use App\Models\User;
use App\Models\VanVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VanVisitLifecycleService
{
    private const TRANSITIONS = [
        'planned' => ['started', 'customer_unavailable'],
        'started' => ['completed_with_order', 'completed_no_order', 'customer_unavailable'],
        'completed_with_order' => ['closed'],
        'completed_no_order' => ['closed'],
        'customer_unavailable' => ['closed'],
        'closed' => [],
    ];

    public function transition(
        VanVisit $visit,
        string $targetStatus,
        User $actor,
        ?int $orderId = null,
        ?int $noOrderReasonId = null,
    ): VanVisit {
        return DB::transaction(function () use ($visit, $targetStatus, $orderId, $noOrderReasonId): VanVisit {
            $locked = VanVisit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $current = (string) $locked->status;

            if ($current === $targetStatus) {
                return $locked;
            }

            $allowed = self::TRANSITIONS[$current] ?? [];
            if (in_array($targetStatus, $allowed, true) === false) {
                throw ValidationException::withMessages([
                    'status' => ["Visit cannot transition from {$current} to {$targetStatus}."],
                ]);
            }

            if ($targetStatus === 'completed_with_order') {
                if ($orderId === null) {
                    throw ValidationException::withMessages([
                        'order_id' => ['An authoritative order is required to complete a visit with order.'],
                    ]);
                }

                $order = Order::query()->whereKey($orderId)->first();
                $customerColumn = (string) $locked->customer_type === 'b2b'
                    ? 'b2b_customer_id'
                    : 'b2c_customer_id';

                if (
                    $order === null
                    || (int) $order->{$customerColumn} !== (int) $locked->customer_id
                    || (
                        $locked->store_id !== null
                        && (int) $order->store_id !== (int) $locked->store_id
                    )
                ) {
                    throw ValidationException::withMessages([
                        'order_id' => ['The order must belong to the same authoritative customer/store scope as the visit.'],
                    ]);
                }
            }

            if ($targetStatus === 'completed_no_order') {
                if ($noOrderReasonId === null) {
                    throw ValidationException::withMessages([
                        'no_order_reason_id' => ['A configured no-order reason is required.'],
                    ]);
                }

                $reasonExists = VanNoOrderReason::query()
                    ->whereKey($noOrderReasonId)
                    ->where('is_active', true)
                    ->exists();

                if ($reasonExists === false) {
                    throw ValidationException::withMessages([
                        'no_order_reason_id' => ['The selected no-order reason is not active.'],
                    ]);
                }
            }

            $changes = ['status' => $targetStatus];

            if ($targetStatus === 'started') {
                $changes['started_at'] = now();
            }

            if (in_array($targetStatus, ['completed_with_order', 'completed_no_order', 'customer_unavailable'], true)) {
                $changes['completed_at'] = now();
            }

            if ($targetStatus === 'closed') {
                $changes['closed_at'] = now();
            }

            if ($targetStatus === 'completed_with_order') {
                $changes['order_id'] = $orderId;
                $changes['no_order_reason_id'] = null;
            } elseif ($targetStatus === 'completed_no_order') {
                $changes['order_id'] = null;
                $changes['no_order_reason_id'] = $noOrderReasonId;
            }

            $locked->forceFill($changes)->save();

            return $locked->fresh();
        }, 3);
    }

    /** @return list<string> */
    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }
}
