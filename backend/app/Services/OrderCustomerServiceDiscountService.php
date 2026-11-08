<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderCustomerServiceDiscountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderLifecycleNotificationService $notifications,
    ) {}

    /**
     * A repeat idempotency key returns false without changing totals or sending
     * another notification. This service must never be used for a paid, issued
     * or currently dispatched order.
     */
    public function apply(
        Order $order,
        User $actor,
        float $percentage,
        string $reason,
        string $idempotencyKey,
        Request $request,
    ): bool {
        return DB::transaction(function () use (
            $order, $actor, $percentage, $reason, $idempotencyKey, $request,
        ): bool {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $id = (int) $locked->getKey();

            if (DB::table('order_customer_service_discounts')
                ->where('order_id', $id)->where('idempotency_key', $idempotencyKey)->exists()) {
                return false;
            }

            if (strtolower((string) $locked->status) !== 'pending'
                || $locked->commercial_locked_at !== null
                || Invoice::query()->where('order_id', $id)
                    ->whereIn('status', ['issued', 'reissued'])->exists()
                || Payment::query()->where('order_id', $id)
                    ->whereNotIn('status', ['pending', 'failed', 'cancelled'])->exists()
                || DB::table('order_dispatch_states')->where('order_id', $id)
                    ->where('status', 'assigned')->whereNotNull('current_assignee_id')->exists()
                || DB::table('driver_assignments')->where('order_id', $id)
                    ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])->exists()
                || DB::table('order_van_assignments')->where('order_id', $id)
                    ->where('status', 'active')->exists()
            ) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            // A taxed order requires the authoritative tax engine to reprice it.
            // Never silently retain an incorrect tax snapshot after a discount.
            if (abs((float) $locked->tax_total) > 0.00001) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.tax_requires_requote')],
                ]);
            }

            $previous = DB::table('order_customer_service_discounts')
                ->where('order_id', $id)->orderByDesc('revision')->first();
            $currency = strtoupper((string) $locked->currency);
            $scale = $currency === 'KWD' ? 3 : 2;
            $subtotal = round((float) $locked->subtotal, $scale);
            $priorTotalDiscount = round((float) $locked->discount_total, $scale);
            $priorCsDiscount = round((float) ($previous->customer_service_discount ?? 0), $scale);
            $baseDiscount = round($priorTotalDiscount - $priorCsDiscount, $scale);
            if ($subtotal < 0 || $baseDiscount < 0 || $baseDiscount > $subtotal
                || $percentage < 0 || $percentage > 100) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.invalid_totals')],
                ]);
            }

            // Replace the CS concession, never compound on a previous CS discount.
            $availableBase = round($subtotal - $baseDiscount, $scale);
            $newCsDiscount = round($availableBase * $percentage / 100, $scale);
            $discountTotalAfter = round($baseDiscount + $newCsDiscount, $scale);
            $grandTotalBefore = round((float) $locked->grand_total, $scale);
            $grandTotalAfter = round(
                $grandTotalBefore - ($discountTotalAfter - $priorTotalDiscount), $scale,
            );
            if ($grandTotalAfter < 0 || ! is_finite($grandTotalAfter)) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.invalid_totals')],
                ]);
            }

            $revision = ((int) ($previous->revision ?? 0)) + 1;
            $snapshot = (array) ($locked->pricing_snapshot ?? []);
            $snapshot['customer_service_discount'] = [
                'revision' => $revision,
                'percentage' => $percentage,
                'amount' => $newCsDiscount,
                'reason' => $reason,
                'applied_by_user_id' => (int) $actor->getKey(),
            ];
            $locked->discount_total = $discountTotalAfter;
            $locked->grand_total = $grandTotalAfter;
            $locked->pricing_snapshot = $snapshot;
            $locked->save();

            DB::table('order_customer_service_discounts')->insert([
                'order_id' => $id,
                'applied_by_user_id' => (int) $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'revision' => $revision,
                'percentage' => $percentage,
                'reason' => $reason,
                'prior_customer_service_discount' => $priorCsDiscount,
                'customer_service_discount' => $newCsDiscount,
                'discount_total_before' => $priorTotalDiscount,
                'discount_total_after' => $discountTotalAfter,
                'grand_total_before' => $grandTotalBefore,
                'grand_total_after' => $grandTotalAfter,
                'order_status' => (string) $locked->status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Payment::query()->where('order_id', $id)->where('status', 'pending')
                ->update(['amount' => $grandTotalAfter]);

            $this->audit->record('order.customer_service_discount.applied', $actor, $locked, [
                'store_id' => (int) $locked->store_id,
                'discount_total' => $priorTotalDiscount,
                'grand_total' => $grandTotalBefore,
                'prior_customer_service_discount' => $priorCsDiscount,
            ], [
                'store_id' => (int) $locked->store_id,
                'discount_total' => $discountTotalAfter,
                'grand_total' => $grandTotalAfter,
                'customer_service_discount' => $newCsDiscount,
                'percentage' => $percentage,
                'reason' => $reason,
                'revision' => $revision,
            ], $request);

            // Persist the deduplicated customer notification in this transaction.
            // Its push job is scheduled after commit by the notification service.
            $this->notifications->customerOrderChanged($locked, 'customer_service_discount', [
                'revision' => $revision,
                'percentage' => $percentage,
                'reason' => $reason,
                'discount_amount' => $newCsDiscount,
                'revised_total' => number_format($grandTotalAfter, $scale, '.', ''),
                'currency' => $currency,
            ]);

            return true;
        }, 3);
    }
}
