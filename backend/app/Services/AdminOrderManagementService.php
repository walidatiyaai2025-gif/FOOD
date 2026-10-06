<?php

namespace App\Services;

use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Invoice;
use App\Models\MarketingCoupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminOrderManagementService
{
    public function __construct(
        private readonly CommerceQuoteService $quotes,
        private readonly CommercialPolicyService $commercialPolicy,
        private readonly OrderInventoryReservationService $reservations,
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
    ) {}

    /** @return array<string, mixed> */
    public function quote(Request $request, string $channel, int $storeId): array
    {
        $channel = strtolower($channel);
        $data = $this->validated($request, $channel);
        $warehouseId = $this->warehouseId($channel, $storeId, $data['warehouse_id'] ?? null);
        [$customer] = $this->customer($channel, $storeId, (int) $data['customer_id']);
        $customerId = (int) $customer->getKey();
        $addressId = $this->addressId($channel, $customerId, $data['address_id'] ?? null);
        $paymentMethod = $this->paymentMethod(
            (string) ($data['payment_method'] ?? config('checkout.default_payment_method')),
            $channel,
            $customer,
        );
        $customerUser = $customer->user_id === null ? null : User::query()->find((int) $customer->user_id);
        $couponCode = isset($data['coupon_code']) && trim((string) $data['coupon_code']) !== ''
            ? strtoupper(trim((string) $data['coupon_code']))
            : null;

        $quote = $this->quotes->quote(
            $channel,
            $storeId,
            $customer,
            $data['items'],
            $customerUser,
            $couponCode,
            $paymentMethod,
            false,
        );

        if ($channel === 'b2b' && $warehouseId !== null) {
            $quote['items'] = array_map(function (array $line) use ($warehouseId): array {
                $available = (float) DB::table('inventories')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', (int) $line['product_id'])
                    ->get(['quantity', 'reserved_quantity'])
                    ->sum(static fn (object $row): float => max(
                        0.0,
                        (float) $row->quantity - (float) $row->reserved_quantity,
                    ));

                $line['available_quantity'] = round($available, 3);
                $line['is_available'] = (bool) $line['is_available']
                    && (float) $line['quantity'] <= $available + 0.0001;

                return $line;
            }, $quote['items']);

            $quote['has_unavailable_items'] = collect($quote['items'])
                ->contains(static fn (array $line): bool => ! (bool) $line['is_available']);
        }

        return [
            ...$quote,
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'address_id' => $addressId,
            'payment_method' => $paymentMethod,
        ];
    }

    public function create(Request $request, User $actor, string $channel, int $storeId): Order
    {
        $channel = strtolower($channel);
        $data = $this->validated($request, $channel);
        $warehouseId = $this->warehouseId($channel, $storeId, $data['warehouse_id'] ?? null);
        [$customer, $legacyCustomerId] = $this->customer($channel, $storeId, (int) $data['customer_id']);
        $customerId = (int) $customer->getKey();
        $addressId = $this->addressId($channel, $customerId, $data['address_id'] ?? null);
        $address = $addressId === null ? null : Address::query()->findOrFail($addressId);
        $paymentMethod = $this->paymentMethod(
            (string) ($data['payment_method'] ?? config('checkout.default_payment_method')),
            $channel,
            $customer,
        );
        $customerUser = $customer->user_id === null ? null : User::query()->find((int) $customer->user_id);
        $couponCode = isset($data['coupon_code']) && trim((string) $data['coupon_code']) !== ''
            ? strtoupper(trim((string) $data['coupon_code']))
            : null;

        $order = DB::transaction(function () use (
            $data,
            $actor,
            $channel,
            $storeId,
            $warehouseId,
            $customer,
            $customerId,
            $legacyCustomerId,
            $addressId,
            $address,
            $paymentMethod,
            $customerUser,
            $couponCode,
            $request,
        ): Order {
            // Dashboard-submitted prices/discount/delivery values are never authoritative.
            $quote = $this->quotes->quote(
                $channel,
                $storeId,
                $customer,
                $data['items'],
                $customerUser,
                $couponCode,
                $paymentMethod,
                true,
            );

            $currency = (string) $quote['currency'];
            $lines = array_map(
                fn (array $line): array => $this->quotes->orderLineSnapshot($line, $currency),
                $quote['items'],
            );
            $header = $this->quotes->orderHeaderSnapshot($quote);
            $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

            $order = Order::query()->create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $legacyCustomerId,
                $customerColumn => $customerId,
                'address_id' => $addressId,
                ...app(OrderDeliveryAddressSnapshotService::class)->attributes($address),
                'order_number' => 'FDX-'.strtoupper($channel).'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'channel' => $channel,
                'status' => 'pending',
                'currency' => $header['currency'],
                'subtotal' => $header['subtotal'],
                'discount_total' => $header['discount_total'],
                'delivery_total' => $header['delivery_total'],
                'tax_total' => $header['tax_total'],
                'grand_total' => $header['grand_total'],
                'quote_id' => $header['quote_id'],
                'quoted_at' => $header['quoted_at'],
                'b2b_account_id_snapshot' => $header['b2b_account_id_snapshot'],
                'price_tier_id_snapshot' => $header['price_tier_id_snapshot'],
                'price_tier_code_snapshot' => $header['price_tier_code_snapshot'],
                'pricing_snapshot' => $header['pricing_snapshot'],
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    ...$line,
                    'selling_unit_quantity' => $line['quantity'],
                    'base_quantity' => $line['quantity'],
                    'conversion_factor_snapshot' => 1,
                ]);

                try {
                    $this->commercialPolicy->reserveBaseQuantityForOrder(
                        (int) $order->getKey(),
                        $legacyCustomerId,
                        (int) $line['product_id'],
                        (float) $line['quantity'],
                        'admin',
                    );
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Commercial policy rejected product '.$line['product_id'].': '.$exception->getMessage(),
                        ],
                    ]);
                }
            }

            $this->reservations->reserve(
                $order,
                $actor,
                collect($lines)->map(fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ])->all(),
                'dashboard_order_created',
            );

            Payment::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => null,
                'provider' => $paymentMethod,
                'provider_reference' => null,
                'status' => 'pending',
                'amount' => $header['grand_total'],
                'currency' => $currency,
                'metadata' => [
                    'method' => $paymentMethod,
                    'source' => 'dashboard',
                    'quote_id' => $header['quote_id'],
                ],
            ]);

            $couponId = $quote['coupon']['id'] ?? null;
            if ($couponId !== null && $customerUser instanceof User) {
                $coupon = MarketingCoupon::query()->find((int) $couponId);
                if ($coupon instanceof MarketingCoupon) {
                    app(CouponRedemptionService::class)->redeem(
                        $coupon,
                        $customerUser,
                        $order,
                        (float) $quote['subtotal'] + (float) $quote['delivery_total'],
                        (float) $quote['coupon_discount_total'],
                    );
                }
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'store_id' => $storeId,
                'user_id' => $actor->getKey(),
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'dashboard_order_created',
            ]);

            $this->audit->record(
                'dashboard.order_created',
                $actor,
                $order,
                null,
                [
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'channel' => $channel,
                    'customer_id' => $customerId,
                    'quote_id' => $header['quote_id'],
                    'grand_total' => $header['grand_total'],
                    'tax_total' => $header['tax_total'],
                    'client_pricing_ignored' => [
                        'discount_total' => $data['discount_total'] ?? null,
                        'delivery_total' => $data['delivery_total'] ?? null,
                    ],
                    'items' => collect($lines)->map(fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                    ])->all(),
                ],
                $request,
            );

            return $order;
        }, 3);

        $this->notifier->orderCreated($order->fresh());

        return $order->fresh();
    }

    public function update(Request $request, User $actor, Order $order): Order
    {
        $channel = strtolower((string) $order->channel);
        $storeId = (int) $order->store_id;
        $data = $this->validated($request, $channel);
        $warehouseId = $this->warehouseId($channel, $storeId, $data['warehouse_id'] ?? null);

        if ((string) $order->status !== 'pending') {
            throw ValidationException::withMessages([
                'order' => ['Only pending orders can be edited.'],
            ]);
        }

        if (
            $order->commercial_locked_at !== null
            || Invoice::query()
                ->where('order_id', $order->getKey())
                ->whereIn('status', ['issued', 'reissued'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'order' => ['Issued commercial snapshots are immutable. Void/reissue the invoice or create a replacement order.'],
            ]);
        }

        if (Payment::query()->where('order_id', $order->getKey())->where('status', 'paid')->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A paid order cannot be edited.'],
            ]);
        }

        [$customer, $legacyCustomerId] = $this->customer($channel, $storeId, (int) $data['customer_id']);
        $customerId = (int) $customer->getKey();
        $addressId = $this->addressId($channel, $customerId, $data['address_id'] ?? null);
        $address = $addressId === null ? null : Address::query()->findOrFail($addressId);
        $paymentMethod = $this->paymentMethod(
            (string) ($data['payment_method'] ?? config('checkout.default_payment_method')),
            $channel,
            $customer,
        );
        $customerUser = $customer->user_id === null ? null : User::query()->find((int) $customer->user_id);
        $existingCouponCode = data_get($order->pricing_snapshot, 'coupon.code');
        $couponCode = array_key_exists('coupon_code', $data)
            ? (trim((string) $data['coupon_code']) === '' ? null : strtoupper(trim((string) $data['coupon_code'])))
            : (is_string($existingCouponCode) && $existingCouponCode !== '' ? $existingCouponCode : null);
        $before = [
            'customer_id' => $order->customer_id,
            'b2b_customer_id' => $order->b2b_customer_id,
            'b2c_customer_id' => $order->b2c_customer_id,
            'address_id' => $order->address_id,
            'warehouse_id' => $order->warehouse_id,
            'quote_id' => $order->quote_id,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
            'tax_total' => (float) ($order->tax_total ?? 0),
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $order->payment_method,
        ];

        return DB::transaction(function () use (
            $request,
            $actor,
            $order,
            $data,
            $channel,
            $storeId,
            $warehouseId,
            $customer,
            $customerId,
            $legacyCustomerId,
            $addressId,
            $address,
            $paymentMethod,
            $customerUser,
            $couponCode,
            $before,
        ): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order' => ['Only pending orders can be edited.'],
                ]);
            }

            if (
                $locked->commercial_locked_at !== null
                || Invoice::query()
                    ->where('order_id', $locked->getKey())
                    ->whereIn('status', ['issued', 'reissued'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'order' => ['Issued commercial snapshots are immutable. Void/reissue the invoice or create a replacement order.'],
                ]);
            }

            // Release this order's own reservations before repricing availability.
            // Any failure rolls this transaction back and restores the original reservation state.
            $this->reservations->release($locked, $actor, 'dashboard_order_edited');
            $this->commercialPolicy->releaseOrderReservations((int) $locked->getKey());

            $quote = $this->quotes->quote(
                $channel,
                $storeId,
                $customer,
                $data['items'],
                $customerUser,
                $couponCode,
                $paymentMethod,
                true,
            );

            $currency = (string) $quote['currency'];
            $lines = array_map(
                fn (array $line): array => $this->quotes->orderLineSnapshot($line, $currency),
                $quote['items'],
            );
            $header = $this->quotes->orderHeaderSnapshot($quote);

            OrderItem::query()->where('order_id', $locked->getKey())->delete();

            $locked->fill([
                'customer_id' => $legacyCustomerId,
                'b2b_customer_id' => $channel === 'b2b' ? $customerId : null,
                'b2c_customer_id' => $channel === 'b2c' ? $customerId : null,
                'warehouse_id' => $warehouseId,
                'address_id' => $addressId,
                ...app(OrderDeliveryAddressSnapshotService::class)->attributes($address),
                'currency' => $header['currency'],
                'subtotal' => $header['subtotal'],
                'discount_total' => $header['discount_total'],
                'delivery_total' => $header['delivery_total'],
                'tax_total' => $header['tax_total'],
                'grand_total' => $header['grand_total'],
                'quote_id' => $header['quote_id'],
                'quoted_at' => $header['quoted_at'],
                'b2b_account_id_snapshot' => $header['b2b_account_id_snapshot'],
                'price_tier_id_snapshot' => $header['price_tier_id_snapshot'],
                'price_tier_code_snapshot' => $header['price_tier_code_snapshot'],
                'pricing_snapshot' => $header['pricing_snapshot'],
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);
            $locked->save();

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $locked->getKey(),
                    ...$line,
                    'selling_unit_quantity' => $line['quantity'],
                    'base_quantity' => $line['quantity'],
                    'conversion_factor_snapshot' => 1,
                ]);

                try {
                    $this->commercialPolicy->reserveBaseQuantityForOrder(
                        (int) $locked->getKey(),
                        $legacyCustomerId,
                        (int) $line['product_id'],
                        (float) $line['quantity'],
                        'admin',
                    );
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Commercial policy rejected product '.$line['product_id'].': '.$exception->getMessage(),
                        ],
                    ]);
                }
            }

            $this->reservations->reserve(
                $locked,
                $actor,
                collect($lines)->map(fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ])->all(),
                'dashboard_order_edited',
            );

            $payment = Payment::query()->where('order_id', $locked->getKey())->latest('id')->first();
            if ($payment instanceof Payment) {
                $payment->update([
                    'provider' => $paymentMethod,
                    'amount' => $header['grand_total'],
                    'currency' => $currency,
                    'metadata' => [
                        'method' => $paymentMethod,
                        'source' => 'dashboard',
                        'quote_id' => $header['quote_id'],
                    ],
                ]);
            } else {
                Payment::query()->create([
                    'order_id' => $locked->getKey(),
                    'invoice_id' => null,
                    'provider' => $paymentMethod,
                    'provider_reference' => null,
                    'status' => 'pending',
                    'amount' => $header['grand_total'],
                    'currency' => $currency,
                    'metadata' => [
                        'method' => $paymentMethod,
                        'source' => 'dashboard',
                        'quote_id' => $header['quote_id'],
                    ],
                ]);
            }

            $this->audit->record(
                'dashboard.order_updated',
                $actor,
                $locked,
                $before,
                [
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'channel' => $channel,
                    'customer_id' => $customerId,
                    'quote_id' => $header['quote_id'],
                    'grand_total' => $header['grand_total'],
                    'tax_total' => $header['tax_total'],
                    'client_pricing_ignored' => [
                        'discount_total' => $data['discount_total'] ?? null,
                        'delivery_total' => $data['delivery_total'] ?? null,
                    ],
                    'items' => collect($lines)->map(fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                    ])->all(),
                ],
                $request,
            );

            return $locked->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $channel): array
    {
        $items = $request->input('items');

        if (is_array($items)) {
            $normalized = [];
            $productIndexes = [];

            foreach ($items as $item) {
                $productId = is_array($item) ? ($item['product_id'] ?? null) : null;
                $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;

                if (! is_numeric($productId) || ! is_numeric($quantity) || (float) $quantity <= 0) {
                    $normalized[] = $item;

                    continue;
                }

                $productKey = (string) (int) $productId;

                if (array_key_exists($productKey, $productIndexes)) {
                    $index = $productIndexes[$productKey];
                    $normalized[$index]['quantity'] = (float) $normalized[$index]['quantity'] + (float) $quantity;

                    continue;
                }

                $productIndexes[$productKey] = count($normalized);
                $normalized[] = $item;
            }

            $request->merge(['items' => array_values($normalized)]);
        }

        return $request->validate([
            'warehouse_id' => $channel === 'b2b'
                ? ['required', 'integer', 'exists:warehouses,id']
                : ['nullable', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', 'max:50'],
            // Accepted for backward-compatible forms but deliberately ignored by pricing authority.
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'delivery_total' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    private function warehouseId(string $channel, int $storeId, mixed $warehouseId): ?int
    {
        if ($channel !== 'b2b') {
            return null;
        }

        $warehouse = DB::table('warehouses')
            ->where('id', (int) $warehouseId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->first(['id']);

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The selected warehouse must belong to the main Wholesale operation.'],
            ]);
        }

        return (int) $warehouse->id;
    }

    /**
     * @return array{0:B2bCustomer|B2cCustomer,1:int}
     */
    private function customer(string $channel, int $storeId, int $customerId): array
    {
        if ($channel === 'b2b') {
            $customer = B2bCustomer::query()->findOrFail($customerId);
            $approved = B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->exists();
            abort_unless($approved, 422, 'An active B2B account is required.');
            abort_unless($customer->legacy_customer_id !== null, 409, 'B2B customer legacy identity is not reconciled.');

            return [$customer, (int) $customer->legacy_customer_id];
        }

        $customer = B2cCustomer::query()
            ->whereKey($customerId)
            ->where('store_id', $storeId)
            ->firstOrFail();
        abort_unless($customer->legacy_customer_id !== null, 409, 'B2C customer legacy identity is not reconciled.');

        return [$customer, (int) $customer->legacy_customer_id];
    }

    private function addressId(string $channel, int $customerId, mixed $addressId): ?int
    {
        if ($addressId === null || $addressId === '') {
            return null;
        }

        $column = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $customerTable = $channel === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $userId = DB::table($customerTable)
            ->where('id', $customerId)
            ->value('user_id');
        $platformCustomerId = $userId === null
            ? null
            : DB::table('platform_customers')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->value('id');

        $address = Address::query()
            ->whereKey((int) $addressId)
            ->where(function ($query) use ($column, $customerId, $platformCustomerId): void {
                $query->where($column, $customerId);

                if ($platformCustomerId !== null) {
                    $query->orWhere('platform_customer_id', (int) $platformCustomerId);
                }
            })
            ->firstOrFail();

        return (int) $address->getKey();
    }

    private function paymentMethod(
        string $paymentMethod,
        string $channel,
        B2bCustomer|B2cCustomer $customer,
    ): string {
        $allowed = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));

        if ($channel === 'b2b' && $customer instanceof B2bCustomer) {
            $account = B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->first();

            if ($account instanceof B2bAccount
                && (float) $account->credit_limit > 0
                && ! in_array('account_credit', $allowed, true)) {
                $allowed[] = 'account_credit';
            }
        }

        if (! in_array($paymentMethod, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['The selected payment method is not configured.'],
            ]);
        }

        return $paymentMethod;
    }
}
