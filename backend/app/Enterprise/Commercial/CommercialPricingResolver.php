<?php

namespace App\Enterprise\Commercial;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Services\CommerceQuoteService;
use Illuminate\Validation\ValidationException;

final class CommercialPricingResolver
{
    public const CONTRACT_VERSION = 1;

    public const MAX_LINES = 100;

    private const ORIGINS = ['sales', 'b2b', 'b2c', 'van'];

    public function __construct(
        private readonly CommerceQuoteService $quotes,
    ) {}

    /**
     * @param  list<array{product_id:int|string,quantity:int|float|string,cart_item_id?:int|string}>  $items
     * @return array<string, mixed>
     */
    public function resolve(
        string $origin,
        string $channel,
        int $storeId,
        B2bCustomer|B2cCustomer|null $customer,
        array $items,
        ?User $customerUser = null,
        ?string $couponCode = null,
        ?string $paymentMethod = null,
    ): array {
        $origin = strtolower(trim($origin));
        $channel = strtolower(trim($channel));

        $this->assertContext($origin, $channel, $storeId, $customer, $items, $paymentMethod);

        $quote = $this->quotes->quote(
            $channel,
            $storeId,
            $customer,
            $items,
            $customerUser,
            $couponCode,
            $paymentMethod,
            true,
        );

        $quote['pricing_contract'] = [
            'version' => self::CONTRACT_VERSION,
            'origin' => $origin,
            'channel' => $channel,
            'store_id' => $storeId,
            'authority' => CommerceQuoteService::class,
            'line_authority' => $channel === 'b2b'
                ? B2bPriceResolver::class
                : 'store_products.price',
            'credit_authority' => $paymentMethod === 'account_credit'
                ? CommercialCreditEligibilityPolicy::class
                : null,
            'precedence' => $this->precedence($channel, $paymentMethod),
            'reason_codes' => $this->reasonCodes($quote, $channel, $paymentMethod),
        ];
        $quote['pricing_snapshot'] = $this->snapshot($quote, $channel);

        return $quote;
    }

    /**
     * @param  list<array{product_id:int|string,quantity:int|float|string,cart_item_id?:int|string}>  $items
     */
    private function assertContext(
        string $origin,
        string $channel,
        int $storeId,
        B2bCustomer|B2cCustomer|null $customer,
        array $items,
        ?string $paymentMethod,
    ): void {
        if (! in_array($origin, self::ORIGINS, true)) {
            throw ValidationException::withMessages([
                'origin' => ['Unsupported commercial pricing origin.'],
            ]);
        }

        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages([
                'channel' => ['Unsupported commercial pricing channel.'],
            ]);
        }

        $originAllowsChannel = $origin === 'sales'
            || (in_array($origin, ['b2b', 'van'], true) && $channel === 'b2b')
            || ($origin === 'b2c' && $channel === 'b2c');
        if (! $originAllowsChannel) {
            throw ValidationException::withMessages([
                'channel' => ['Commercial pricing origin does not support this channel.'],
            ]);
        }

        if ($storeId < 1) {
            throw ValidationException::withMessages([
                'store_id' => ['A valid store is required for commercial pricing.'],
            ]);
        }

        if ($items === [] || count($items) > self::MAX_LINES) {
            throw ValidationException::withMessages([
                'items' => ['Commercial pricing requires between 1 and '.self::MAX_LINES.' lines.'],
            ]);
        }

        if ($channel === 'b2b' && ! $customer instanceof B2bCustomer) {
            throw ValidationException::withMessages([
                'customer' => ['B2B pricing requires an authoritative B2B customer context.'],
            ]);
        }

        if ($channel === 'b2c' && $customer !== null && ! $customer instanceof B2cCustomer) {
            throw ValidationException::withMessages([
                'customer' => ['B2C pricing requires an authoritative B2C customer context.'],
            ]);
        }

        if ($paymentMethod === 'account_credit' && $channel !== 'b2b') {
            throw ValidationException::withMessages([
                'payment_method' => ['Account credit is available only for B2B pricing.'],
            ]);
        }
    }

    /** @return list<string> */
    private function precedence(string $channel, ?string $paymentMethod): array
    {
        $steps = $channel === 'b2b'
            ? [
                'commercial_policy',
                'b2b_price_resolver',
                'store_base_price_fallback_when_authorized',
                'coupon_after_line_resolution',
                'delivery',
                'tax',
            ]
            : [
                'commercial_policy',
                'store_product_price',
                'promotion_after_line_resolution',
                'coupon_after_promotion_capped_once',
                'delivery',
                'tax',
            ];

        if ($paymentMethod === 'account_credit') {
            $steps[] = 'commercial_credit_eligibility_policy';
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return list<string>
     */
    private function reasonCodes(array $quote, string $channel, ?string $paymentMethod): array
    {
        $codes = [
            $channel === 'b2b'
                ? 'CANONICAL_B2B_PRICE_RESOLVER'
                : 'CANONICAL_B2C_STORE_PRICE',
        ];

        if (($quote['promotion'] ?? null) !== null) {
            $codes[] = 'PROMOTION_APPLIED_ONCE';
        }

        if (($quote['coupon'] ?? null) !== null) {
            $codes[] = 'COUPON_APPLIED_ONCE';
        }

        if ($paymentMethod === 'account_credit') {
            $codes[] = CommercialCreditEligibilityPolicy::REASON_ELIGIBLE;
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function snapshot(array $quote, string $channel): array
    {
        $lines = [];
        foreach ((array) ($quote['items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_price' => $line['unit_price'] === null ? null : (float) $line['unit_price'],
                'base_unit_price' => $line['base_unit_price'] === null
                    ? null
                    : (float) $line['base_unit_price'],
                'price_tier_id' => $line['price_tier_id'] ?? null,
                'price_tier_code' => $line['price_tier_code'] ?? null,
                'minimum_order_quantity' => $line['minimum_order_quantity'] ?? null,
                'ordering_increment' => $line['ordering_increment'] ?? null,
                'pack_size' => $line['pack_size'] ?? null,
                'case_size' => $line['case_size'] ?? null,
                'authority' => $channel === 'b2b'
                    ? B2bPriceResolver::class
                    : 'store_products.price',
                'reason_codes' => (array) ($line['commercial_reason_codes'] ?? []),
            ];
        }

        return [
            'quote_id' => (string) ($quote['quote_id'] ?? ''),
            'quoted_at' => (string) ($quote['quoted_at'] ?? ''),
            'pricing_source' => (string) ($quote['pricing_source'] ?? ''),
            'currency' => (string) ($quote['currency'] ?? ''),
            'customer_context' => (array) ($quote['customer_context'] ?? []),
            'lines' => $lines,
            'promotion' => $quote['promotion'] ?? null,
            'coupon' => $quote['coupon'] ?? null,
            'subtotal' => (float) ($quote['subtotal'] ?? 0),
            'discount_total' => (float) ($quote['discount_total'] ?? 0),
            'delivery_total' => (float) ($quote['delivery_total'] ?? 0),
            'tax_total' => (float) ($quote['tax_total'] ?? 0),
            'grand_total' => (float) ($quote['grand_total'] ?? 0),
        ];
    }
}
