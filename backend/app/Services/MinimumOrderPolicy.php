<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Applies the same order minimum across native customer and Smart Ordering checkouts.
 * Only EGP merchandise after product discounts counts; shipping, service and tax do not.
 */
final class MinimumOrderPolicy
{
    /** @param array<string, mixed> $quote */
    public function merchandiseTotal(array $quote): float
    {
        $subtotal = max(0.0, (float) ($quote['subtotal'] ?? 0));
        $discount = max(0.0, (float) ($quote['discount_total'] ?? 0));

        // Smart Ordering quotes explicitly separate delivery discounts.
        // Standard commerce quotes include free-shipping discounts in discount_total.
        if (! array_key_exists('delivery_discount_total', $quote)) {
            if (strtolower((string) ($quote['promotion']['type'] ?? '')) === 'free_shipping') {
                $discount -= max(0.0, (float) ($quote['promotion_discount_total'] ?? 0));
            }

            if (strtolower((string) ($quote['coupon']['discount_type'] ?? '')) === 'free_shipping') {
                $discount -= max(0.0, (float) ($quote['coupon_discount_total'] ?? 0));
            }
        }

        return round(max(0.0, $subtotal - max(0.0, $discount)), 3);
    }

    /** @param array<string, mixed> $quote */
    public function assertEligible(array $quote, string $field = 'cart'): void
    {
        if (strtoupper((string) ($quote['currency'] ?? '')) !== 'EGP') {
            return;
        }

        $minimum = max(0.0, (float) config('checkout.minimum_merchandise_total_egp', 1500));
        $remaining = round($minimum - $this->merchandiseTotal($quote), 2);
        if ($remaining <= 0) {
            return;
        }

        $message = app()->getLocale() === 'ar'
            ? 'الحد الأدنى للطلب '.number_format($minimum, 2).' جنيه. أضف منتجات بقيمة '.number_format($remaining, 2).' جنيه لإتمام الطلب.'
            : 'Minimum order is EGP '.number_format($minimum, 2).'. Add EGP '.number_format($remaining, 2).' in products to check out.';

        throw ValidationException::withMessages([$field => [$message]]);
    }
}
