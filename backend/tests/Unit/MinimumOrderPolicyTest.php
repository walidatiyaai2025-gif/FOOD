<?php

namespace Tests\Unit;

use App\Services\MinimumOrderPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MinimumOrderPolicyTest extends TestCase
{
    public function test_shipping_promotion_does_not_reduce_merchandise_threshold(): void
    {
        $policy = new MinimumOrderPolicy;

        $this->assertSame(1500.0, $policy->merchandiseTotal([
            'subtotal' => 1500,
            'discount_total' => 65,
            'promotion_discount_total' => 65,
            'promotion' => ['type' => 'free_shipping'],
        ]));
    }

    public function test_product_discounts_lower_merchandise_threshold(): void
    {
        $policy = new MinimumOrderPolicy;

        $this->assertSame(1450.0, $policy->merchandiseTotal([
            'subtotal' => 1500,
            'discount_total' => 50,
            'coupon_discount_total' => 50,
            'coupon' => ['discount_type' => 'fixed'],
        ]));
    }

    public function test_smart_ordering_delivery_discount_is_separate(): void
    {
        $policy = new MinimumOrderPolicy;

        $this->assertSame(1500.0, $policy->merchandiseTotal([
            'subtotal' => 1500,
            'discount_total' => 0,
            'delivery_discount_total' => 65,
        ]));
    }

    public function test_below_minimum_is_rejected_and_exact_minimum_passes(): void
    {
        config()->set('checkout.minimum_merchandise_total_egp', 1500);
        $policy = new MinimumOrderPolicy;
        $policy->assertEligible(['currency' => 'EGP', 'subtotal' => 1500, 'discount_total' => 0]);

        $this->expectException(ValidationException::class);
        $policy->assertEligible(['currency' => 'EGP', 'subtotal' => 1499, 'discount_total' => 0]);
    }
}
