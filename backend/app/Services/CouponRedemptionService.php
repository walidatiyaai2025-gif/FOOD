<?php

namespace App\Services;

use App\Models\MarketingCoupon;
use App\Models\MarketingCouponRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CouponRedemptionService
{
    /**
     * @return array{coupon:MarketingCoupon,discount_total:float,delivery_total:float}
     */
    public function quote(
        string $code,
        string $channel,
        int $storeId,
        int $customerId,
        User $user,
        float $subtotal,
        float $deliveryTotal,
    ): array {
        $normalized = strtoupper(trim($code));
        $scopeKey = $channel === 'b2b' ? 'b2b' : 'b2c:'.$storeId;

        $coupon = MarketingCoupon::query()
            ->where('scope_key', $scopeKey)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->lockForUpdate()
            ->first();

        if (! $coupon instanceof MarketingCoupon || ! $coupon->is_active) {
            $this->invalid('Coupon is invalid or inactive.');
        }

        if ($channel === 'b2c') {
            $enabled = \Illuminate\Support\Facades\DB::table('stores')
                ->where('id', $storeId)
                ->where('is_active', true)
                ->where('coupons_enabled', true)
                ->exists();
            if (! $enabled) {
                $this->invalid('Coupons are not enabled for this retail store.');
            }
        }

        $now = now();
        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            $this->invalid('Coupon is not active yet.');
        }
        if ($coupon->ends_at !== null && $now->gt($coupon->ends_at)) {
            $this->invalid('Coupon has expired.');
        }

        if ($coupon->usage_limit_total !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit_total) {
            $this->invalid('Coupon usage limit has been reached.');
        }

        if ($coupon->usage_limit_per_user !== null) {
            $usedByUser = MarketingCouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->count();
            if ($usedByUser >= (int) $coupon->usage_limit_per_user) {
                $this->invalid('Coupon usage limit for this user has been reached.');
            }
        }

        if ($coupon->minimum_order_amount !== null && $subtotal + 0.0001 < (float) $coupon->minimum_order_amount) {
            $this->invalid('Order total is below the coupon minimum.');
        }

        if ($coupon->first_order_only) {
            $column = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
            if (Order::query()->where($column, $customerId)->exists()) {
                $this->invalid('Coupon is valid for the first order only.');
            }
        }

        $discount = 0.0;
        $finalDelivery = $deliveryTotal;

        if ($coupon->discount_type === 'percentage') {
            $discount = round($subtotal * ((float) $coupon->discount_value / 100), 3);
            if ($coupon->maximum_discount_amount !== null) {
                $discount = min($discount, (float) $coupon->maximum_discount_amount);
            }
        } elseif ($coupon->discount_type === 'fixed') {
            $discount = min($subtotal, (float) $coupon->discount_value);
        } elseif ($coupon->discount_type === 'free_shipping') {
            $discount = max(0, $deliveryTotal);
            $finalDelivery = 0.0;
        } else {
            $this->invalid('Coupon discount type is not supported.');
        }

        return [
            'coupon' => $coupon,
            'discount_total' => round(max(0, $discount), 3),
            'delivery_total' => round(max(0, $finalDelivery), 3),
        ];
    }

    public function redeem(
        MarketingCoupon $coupon,
        User $user,
        Order $order,
        float $orderAmount,
        float $discountAmount,
    ): void {
        MarketingCouponRedemption::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_amount' => round($orderAmount, 3),
            'discount_amount' => round($discountAmount, 3),
            'redeemed_at' => now(),
        ]);

        MarketingCoupon::query()->whereKey($coupon->id)->increment('used_count');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['coupon_code' => [$message]]);
    }
}
