<?php

namespace App\Services;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\B2cCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommerceQuoteService
{
    public function __construct(
        private readonly B2bPriceResolver $b2bPricing,
        private readonly CouponRedemptionService $coupons,
    ) {}

    /**
     * Reprice one exact cart from live server-side commercial rules.
     *
     * @return array<string, mixed>
     */
    public function quoteCart(
        Cart $cart,
        ?User $user = null,
        ?string $couponCode = null,
        ?string $paymentMethod = null,
        bool $strict = false,
    ): array {
        $channel = strtolower((string) $cart->channel);
        $customer = null;

        if ($channel === 'b2b' && $cart->b2b_customer_id !== null) {
            $customer = B2bCustomer::query()->find((int) $cart->b2b_customer_id);
        } elseif ($channel === 'b2c' && $cart->b2c_customer_id !== null) {
            $customer = B2cCustomer::query()->find((int) $cart->b2c_customer_id);
        }

        if ($user === null && ($customer instanceof B2bCustomer || $customer instanceof B2cCustomer) && $customer->user_id !== null) {
            $user = User::query()->find((int) $customer->user_id);
        }

        $items = CartItem::query()
            ->where('cart_id', $cart->getKey())
            ->orderBy('id')
            ->get(['id', 'product_id', 'quantity'])
            ->map(static fn (CartItem $item): array => [
                'cart_item_id' => (int) $item->getKey(),
                'product_id' => (int) $item->product_id,
                'quantity' => (float) $item->quantity,
            ])
            ->all();

        if ($strict && $items === []) {
            abort(409, 'The cart is empty.');
        }

        $quote = $this->quote(
            $channel,
            (int) $cart->store_id,
            $customer,
            $items,
            $user,
            $couponCode,
            $paymentMethod,
            $strict,
        );

        foreach ($quote['items'] as $line) {
            if (! isset($line['cart_item_id'])) {
                continue;
            }

            DB::table('cart_items')
                ->where('id', (int) $line['cart_item_id'])
                ->where('cart_id', $cart->getKey())
                ->update([
                    'unit_price_snapshot' => $line['unit_price'],
                    'updated_at' => now(),
                ]);
        }

        return $quote;
    }

    /**
     * Quote arbitrary server-authorized lines. Client-submitted prices are intentionally absent.
     *
     * @param  list<array{product_id:int|string,quantity:int|float|string,cart_item_id?:int|string}>  $items
     * @return array<string, mixed>
     */
    public function quote(
        string $channel,
        int $storeId,
        B2bCustomer|B2cCustomer|null $customer,
        array $items,
        ?User $customerUser = null,
        ?string $couponCode = null,
        ?string $paymentMethod = null,
        bool $strict = true,
    ): array {
        $channel = strtolower(trim($channel));
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422, 'Unsupported commerce channel.');

        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', strtoupper($channel))
            ->first(['stores.id', 'stores.name']);

        abort_unless($store !== null, 404, 'Store is not available for this commerce channel.');

        if ($channel === 'b2b') {
            abort_unless($customer instanceof B2bCustomer, 403, 'An active B2B customer context is required.');
        } elseif ($customer !== null) {
            abort_unless(
                $customer instanceof B2cCustomer && (int) $customer->store_id === $storeId,
                404,
                'Retail customer does not belong to this store.',
            );
        }

        if ($customerUser === null && ($customer instanceof B2bCustomer || $customer instanceof B2cCustomer) && $customer->user_id !== null) {
            $customerUser = User::query()->find((int) $customer->user_id);
        }

        $b2bContext = $channel === 'b2b'
            ? $this->b2bContext($customer)
            : ['account' => null, 'tier' => null];

        $lines = [];
        $subtotal = 0.0;
        $hasUnavailableItems = false;

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = round((float) $item['quantity'], 3);

            if ($productId <= 0 || $quantity <= 0) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'items' => ['Every quote line requires a valid product and positive quantity.'],
                    ]);
                }

                $hasUnavailableItems = true;

                continue;
            }

            $line = $this->line(
                $channel,
                $storeId,
                $customer,
                $productId,
                $quantity,
                $strict,
                $b2bContext,
            );

            if (array_key_exists('cart_item_id', $item)) {
                $line['cart_item_id'] = (int) $item['cart_item_id'];
            }

            if (! $line['is_available']) {
                $hasUnavailableItems = true;
            } elseif ($line['line_total'] !== null) {
                $subtotal += (float) $line['line_total'];
            }

            $lines[] = $line;
        }

        $subtotal = round($subtotal, 3);
        $deliveryTotal = $this->deliveryFee($storeId);
        [$promotionDiscount, $promotion] = $this->promotion($channel, $storeId, $subtotal, $deliveryTotal);

        $couponDiscount = 0.0;
        $coupon = null;
        $normalizedCoupon = strtoupper(trim((string) $couponCode));

        if ($normalizedCoupon !== '') {
            if ($customerUser === null || $customer === null) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['An authenticated customer is required to apply a coupon.'],
                ]);
            }

            $couponQuote = $this->coupons->quote(
                $normalizedCoupon,
                $channel,
                $storeId,
                (int) $customer->getKey(),
                $customerUser,
                $subtotal,
                $deliveryTotal,
            );

            $couponDiscount = (float) $couponQuote['discount_total'];
            $coupon = [
                'id' => (int) $couponQuote['coupon']->getKey(),
                'code' => (string) $couponQuote['coupon']->code,
                'discount_type' => (string) $couponQuote['coupon']->discount_type,
                'discount_value' => (float) $couponQuote['coupon']->discount_value,
            ];
        }

        $maximumDiscount = max(0.0, $subtotal + $deliveryTotal);
        $promotionDiscount = min($promotionDiscount, $maximumDiscount);
        $couponDiscount = min($couponDiscount, max(0.0, $maximumDiscount - $promotionDiscount));
        $discountTotal = round($promotionDiscount + $couponDiscount, 3);

        $taxRate = $this->taxRate($storeId);
        $taxableAmount = max(0.0, $subtotal + $deliveryTotal - $discountTotal);
        $taxTotal = round($taxableAmount * ($taxRate / 100), 3);
        $grandTotal = round($taxableAmount + $taxTotal, 3);

        if ($channel === 'b2b' && $paymentMethod === 'account_credit') {
            /** @var B2bAccount|null $account */
            $account = $b2bContext['account'];
            abort_unless($account instanceof B2bAccount, 403, 'An active B2B account is required.');
            $finance = app(B2bAccountLedgerService::class)->summary($customer, $storeId);
            abort_if(
                (float) $finance['purchasing_power'] < $grandTotal,
                409,
                'The order exceeds the available B2B purchasing power.',
            );
        }

        $currency = $this->currency($storeId);
        $quotedAt = now();

        return [
            'quote_id' => (string) Str::uuid(),
            'quoted_at' => $quotedAt->toAtomString(),
            'store_id' => $storeId,
            'store_name' => (string) $store->name,
            'channel' => $channel,
            'currency' => $currency,
            'customer_context' => [
                'customer_id' => $customer?->getKey(),
                'b2b_account_id' => $b2bContext['account'] instanceof B2bAccount
                    ? (int) $b2bContext['account']->getKey()
                    : null,
                'price_tier_id' => $b2bContext['tier'] instanceof B2bPriceTier
                    ? (int) $b2bContext['tier']->getKey()
                    : null,
                'price_tier_code' => $b2bContext['tier'] instanceof B2bPriceTier
                    ? (string) $b2bContext['tier']->code
                    : null,
            ],
            'items' => $lines,
            'subtotal' => $subtotal,
            'promotion_discount_total' => round($promotionDiscount, 3),
            'coupon_discount_total' => round($couponDiscount, 3),
            'discount_total' => $discountTotal,
            'delivery_total' => $deliveryTotal,
            'tax_rate' => $taxRate,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'promotion' => $promotion,
            'coupon' => $coupon,
            'has_unavailable_items' => $hasUnavailableItems,
            'pricing_source' => 'backend_authoritative_quote_v1',
        ];
    }

    /** @return array<string, mixed> */
    public function orderHeaderSnapshot(array $quote): array
    {
        $context = is_array($quote['customer_context'] ?? null) ? $quote['customer_context'] : [];

        return [
            'quote_id' => $quote['quote_id'],
            'quoted_at' => $quote['quoted_at'],
            'currency' => $quote['currency'],
            'subtotal' => $quote['subtotal'],
            'discount_total' => $quote['discount_total'],
            'delivery_total' => $quote['delivery_total'],
            'tax_total' => $quote['tax_total'],
            'grand_total' => $quote['grand_total'],
            'b2b_account_id_snapshot' => $context['b2b_account_id'] ?? null,
            'price_tier_id_snapshot' => $context['price_tier_id'] ?? null,
            'price_tier_code_snapshot' => $context['price_tier_code'] ?? null,
            'pricing_snapshot' => [
                'source' => $quote['pricing_source'],
                'quote_id' => $quote['quote_id'],
                'quoted_at' => $quote['quoted_at'],
                'promotion' => $quote['promotion'],
                'coupon' => $quote['coupon'],
                'promotion_discount_total' => $quote['promotion_discount_total'],
                'coupon_discount_total' => $quote['coupon_discount_total'],
                'tax_rate' => $quote['tax_rate'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function orderLineSnapshot(array $line, string $currency): array
    {
        return [
            'product_id' => (int) $line['product_id'],
            'sku_snapshot' => (string) $line['sku'],
            'name_snapshot' => (string) $line['name'],
            'quantity' => (float) $line['quantity'],
            'quantity_conversion_factor' => (float) ($line['quantity_conversion_factor'] ?? 1),
            'unit_price' => (float) $line['unit_price'],
            'base_unit_price_snapshot' => $line['base_unit_price'] === null ? null : (float) $line['base_unit_price'],
            'line_discount_total' => (float) ($line['line_discount_total'] ?? 0),
            'line_tax_total' => (float) ($line['line_tax_total'] ?? 0),
            'line_total' => (float) $line['line_total'],
            'currency' => $currency,
            'b2b_account_id_snapshot' => $line['b2b_account_id'] ?? null,
            'price_tier_id_snapshot' => $line['price_tier_id'] ?? null,
            'price_tier_code_snapshot' => $line['price_tier_code'] ?? null,
            'minimum_quantity_snapshot' => $line['minimum_order_quantity'] ?? null,
            'ordering_increment_snapshot' => $line['ordering_increment'] ?? null,
            'pack_size_snapshot' => $line['pack_size'] ?? null,
            'case_size_snapshot' => $line['case_size'] ?? null,
        ];
    }

    /**
     * @param  array{account:B2bAccount|null,tier:B2bPriceTier|null}  $b2bContext
     * @return array<string, mixed>
     */
    private function line(
        string $channel,
        int $storeId,
        B2bCustomer|B2cCustomer|null $customer,
        int $productId,
        float $quantity,
        bool $strict,
        array $b2bContext,
    ): array {
        $product = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $storeId);
            })
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', $channel)
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->first([
                'products.id',
                'products.sku',
                'products.name',
                'products.category_id',
                'products.brand_id',
                'store_products.price',
            ]);

        if ($product === null) {
            if ($strict) {
                abort(404, 'Product is not available in the selected store.');
            }

            return [
                'product_id' => $productId,
                'sku' => null,
                'name' => null,
                'category_id' => null,
                'brand_id' => null,
                'quantity' => $quantity,
                'base_unit_price' => null,
                'unit_price' => null,
                'line_subtotal' => null,
                'line_discount_total' => 0.0,
                'line_tax_total' => 0.0,
                'line_total' => null,
                'is_available' => false,
                'available_quantity' => 0.0,
                'availability_state' => 'OUT_OF_STOCK',
                'minimum_order_quantity' => null,
                'ordering_increment' => null,
                'pack_size' => null,
                'case_size' => null,
                'pack_label' => null,
                'quantity_conversion_factor' => 1.0,
                'b2b_account_id' => null,
                'price_tier_id' => null,
                'price_tier_code' => null,
            ];
        }

        $inventoryRows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->get(['inventories.quantity', 'inventories.reserved_quantity']);

        $availableQuantity = $inventoryRows->isEmpty()
            ? null
            : (float) $inventoryRows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            );

        $isAvailable = $availableQuantity === null || $quantity <= $availableQuantity + 0.0001;
        $unitPrice = (float) $product->price;
        $minimum = null;
        $increment = null;
        $packSize = null;
        $caseSize = null;
        $packLabel = null;
        $accountId = null;
        $tierId = null;
        $tierCode = null;

        if ($channel === 'b2b') {
            abort_unless($customer instanceof B2bCustomer, 403, 'An active B2B customer context is required.');

            $pricing = $this->b2bPricing->resolve($customer, $storeId, $productId);
            $minimum = (float) $pricing['minimum_quantity'];
            $increment = max(0.001, (float) $pricing['ordering_increment']);
            $packSize = max(0.001, (float) $pricing['pack_size']);
            $caseSize = $pricing['case_size'] === null ? null : (float) $pricing['case_size'];
            $packLabel = $pricing['pack_label'];
            $unitPrice = (float) $pricing['price'];

            $steps = ($quantity - $minimum) / $increment;
            $validQuantity = $quantity + 0.0001 >= $minimum
                && abs($steps - round($steps)) < 0.0001;

            if (! $validQuantity) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'items' => ['Quantity must meet the B2B minimum and ordering increment.'],
                    ]);
                }
                $isAvailable = false;
            }

            $accountId = $b2bContext['account'] instanceof B2bAccount
                ? (int) $b2bContext['account']->getKey()
                : null;
            $tierId = $b2bContext['tier'] instanceof B2bPriceTier
                ? (int) $b2bContext['tier']->getKey()
                : null;
            $tierCode = $b2bContext['tier'] instanceof B2bPriceTier
                ? (string) $b2bContext['tier']->code
                : null;
        }

        if (! $isAvailable && $strict) {
            abort(409, 'Requested quantity exceeds available stock.');
        }

        $lineSubtotal = $isAvailable ? round($quantity * $unitPrice, 3) : null;

        return [
            'product_id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'quantity' => $quantity,
            'base_unit_price' => (float) $product->price,
            'unit_price' => $isAvailable ? round($unitPrice, 3) : null,
            'line_subtotal' => $lineSubtotal,
            'line_discount_total' => 0.0,
            'line_tax_total' => 0.0,
            'line_total' => $lineSubtotal,
            'is_available' => $isAvailable,
            'available_quantity' => $availableQuantity,
            'availability_state' => $availableQuantity !== null && $availableQuantity <= 0 ? 'OUT_OF_STOCK' : 'AVAILABLE',
            'minimum_order_quantity' => $minimum,
            'ordering_increment' => $increment,
            'pack_size' => $packSize,
            'case_size' => $caseSize,
            'pack_label' => $packLabel,
            'quantity_conversion_factor' => $packSize ?? 1.0,
            'b2b_account_id' => $accountId,
            'price_tier_id' => $tierId,
            'price_tier_code' => $tierCode,
        ];
    }

    /** @return array{account:B2bAccount|null,tier:B2bPriceTier|null} */
    private function b2bContext(B2bCustomer $customer): array
    {
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();

        abort_unless($account instanceof B2bAccount, 403, 'An active B2B account is required.');

        $tier = $account->price_tier_id === null
            ? null
            : B2bPriceTier::query()->find((int) $account->price_tier_id);

        return ['account' => $account, 'tier' => $tier];
    }

    /** @return array{0:float,1:array<string,mixed>|null} */
    private function promotion(string $channel, int $storeId, float $subtotal, float $deliveryTotal): array
    {
        if ($channel !== 'b2c' || $subtotal <= 0) {
            return [0.0, null];
        }

        $now = now();
        $promotions = Promotion::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('id')
            ->get();

        $bestDiscount = 0.0;
        $best = null;

        foreach ($promotions as $promotion) {
            $type = strtolower(trim((string) $promotion->type));
            $value = max(0.0, (float) ($promotion->value ?? 0));
            $discount = match ($type) {
                'percentage', 'percent' => round($subtotal * (min(100.0, $value) / 100), 3),
                'fixed', 'fixed_amount', 'amount' => min($subtotal, $value),
                'free_shipping' => max(0.0, $deliveryTotal),
                default => 0.0,
            };

            if ($discount <= $bestDiscount) {
                continue;
            }

            $bestDiscount = $discount;
            $best = [
                'id' => (int) $promotion->getKey(),
                'name' => (string) $promotion->name,
                'type' => (string) $promotion->type,
                'value' => $promotion->value === null ? null : (float) $promotion->value,
            ];
        }

        return [round($bestDiscount, 3), $best];
    }

    private function deliveryFee(int $storeId): float
    {
        return round(max(0.0, $this->settingFloat(
            $storeId,
            ['checkout.delivery_fee', 'delivery_fee'],
            (float) config('checkout.delivery_fee', 0),
        )), 3);
    }

    private function taxRate(int $storeId): float
    {
        return round(min(100.0, max(0.0, $this->settingFloat(
            $storeId,
            ['checkout.tax_rate', 'tax_rate'],
            0.0,
        ))), 4);
    }

    private function currency(int $storeId): string
    {
        $value = $this->settingValue($storeId, ['checkout.currency', 'currency']);

        if (is_string($value)) {
            $currency = strtoupper(trim($value));
            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                return $currency;
            }
        }

        return 'EGP';
    }

    private function settingFloat(int $storeId, array $keys, float $default): float
    {
        $value = $this->settingValue($storeId, $keys);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function settingValue(int $storeId, array $keys): mixed
    {
        foreach ([$storeId, null] as $scopeStoreId) {
            foreach ($keys as $key) {
                $query = DB::table('settings')->where('key', $key);
                $scopeStoreId === null
                    ? $query->whereNull('store_id')
                    : $query->where('store_id', $scopeStoreId);

                $raw = $query->orderByDesc('id')->value('value');

                if ($raw === null) {
                    continue;
                }

                if (! is_string($raw)) {
                    return $raw;
                }

                $decoded = json_decode($raw, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
            }
        }

        return null;
    }
}
