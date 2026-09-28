<?php

namespace App\Services;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminOrderManagementService
{
    public function __construct(
        private readonly B2bPriceResolver $b2bPricing,
        private readonly OrderInventoryReservationService $reservations,
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
    ) {}

    public function create(Request $request, User $actor, string $channel, int $storeId): Order
    {
        $data = $this->validated($request, $channel);
        $warehouseId = $this->warehouseId($channel, $storeId, $data['warehouse_id'] ?? null);
        [$customerId, $legacyCustomerId] = $this->customerIds($channel, $storeId, (int) $data['customer_id']);
        $addressId = $this->addressId($channel, $customerId, $data['address_id'] ?? null);
        $lines = $this->lineSnapshots($channel, $storeId, $customerId, $data['items']);
        $totals = $this->totals($lines, (float) ($data['discount_total'] ?? 0), (float) ($data['delivery_total'] ?? 0));
        $paymentMethod = $this->paymentMethod((string) ($data['payment_method'] ?? config('checkout.default_payment_method')));

        $order = DB::transaction(function () use (
            $data,
            $actor,
            $channel,
            $storeId,
            $warehouseId,
            $customerId,
            $legacyCustomerId,
            $addressId,
            $lines,
            $totals,
            $paymentMethod,
            $request,
        ): Order {
            $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

            $order = Order::query()->create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $legacyCustomerId,
                $customerColumn => $customerId,
                'address_id' => $addressId,
                'order_number' => 'FDX-'.strtoupper($channel).'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'channel' => $channel,
                'status' => 'pending',
                'currency' => 'EGP',
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'delivery_total' => $totals['delivery_total'],
                'grand_total' => $totals['grand_total'],
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    ...$line,
                ]);
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
                'amount' => $totals['grand_total'],
                'currency' => 'EGP',
                'metadata' => ['method' => $paymentMethod, 'source' => 'dashboard'],
            ]);

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
                    'grand_total' => $totals['grand_total'],
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

        if (Payment::query()->where('order_id', $order->getKey())->where('status', 'paid')->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A paid order cannot be edited.'],
            ]);
        }

        [$customerId, $legacyCustomerId] = $this->customerIds($channel, $storeId, (int) $data['customer_id']);
        $addressId = $this->addressId($channel, $customerId, $data['address_id'] ?? null);
        $lines = $this->lineSnapshots($channel, $storeId, $customerId, $data['items']);
        $totals = $this->totals($lines, (float) ($data['discount_total'] ?? 0), (float) ($data['delivery_total'] ?? 0));
        $paymentMethod = $this->paymentMethod((string) ($data['payment_method'] ?? config('checkout.default_payment_method')));
        $before = [
            'customer_id' => $order->customer_id,
            'b2b_customer_id' => $order->b2b_customer_id,
            'b2c_customer_id' => $order->b2c_customer_id,
            'address_id' => $order->address_id,
            'warehouse_id' => $order->warehouse_id,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
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
            $customerId,
            $legacyCustomerId,
            $addressId,
            $lines,
            $totals,
            $paymentMethod,
            $before,
        ): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order' => ['Only pending orders can be edited.'],
                ]);
            }

            $this->reservations->release($locked, $actor, 'dashboard_order_edited');

            OrderItem::query()->where('order_id', $locked->getKey())->delete();

            $locked->fill([
                'customer_id' => $legacyCustomerId,
                'b2b_customer_id' => $channel === 'b2b' ? $customerId : null,
                'b2c_customer_id' => $channel === 'b2c' ? $customerId : null,
                'warehouse_id' => $warehouseId,
                'address_id' => $addressId,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'delivery_total' => $totals['delivery_total'],
                'grand_total' => $totals['grand_total'],
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);
            $locked->save();

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $locked->getKey(),
                    ...$line,
                ]);
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
                    'amount' => $totals['grand_total'],
                    'currency' => 'EGP',
                    'metadata' => ['method' => $paymentMethod, 'source' => 'dashboard'],
                ]);
            } else {
                Payment::query()->create([
                    'order_id' => $locked->getKey(),
                    'invoice_id' => null,
                    'provider' => $paymentMethod,
                    'provider_reference' => null,
                    'status' => 'pending',
                    'amount' => $totals['grand_total'],
                    'currency' => 'EGP',
                    'metadata' => ['method' => $paymentMethod, 'source' => 'dashboard'],
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
                    'grand_total' => $totals['grand_total'],
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
        return $request->validate([
            'warehouse_id' => $channel === 'b2b'
                ? ['required', 'integer', 'exists:warehouses,id']
                : ['nullable', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', 'max:50'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'delivery_total' => ['nullable', 'numeric', 'min:0'],
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

    /** @return array{0: int, 1: int} domain customer id, legacy customer id */
    private function customerIds(string $channel, int $storeId, int $customerId): array
    {
        if ($channel === 'b2b') {
            $customer = B2bCustomer::query()->findOrFail($customerId);
            $approved = B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->exists();
            abort_unless($approved, 422, 'An active B2B account is required.');
            abort_unless($customer->legacy_customer_id !== null, 409, 'B2B customer legacy identity is not reconciled.');

            return [(int) $customer->getKey(), (int) $customer->legacy_customer_id];
        }

        $customer = B2cCustomer::query()
            ->whereKey($customerId)
            ->where('store_id', $storeId)
            ->firstOrFail();
        abort_unless($customer->legacy_customer_id !== null, 409, 'B2C customer legacy identity is not reconciled.');

        return [(int) $customer->getKey(), (int) $customer->legacy_customer_id];
    }

    private function addressId(string $channel, int $customerId, mixed $addressId): ?int
    {
        if ($addressId === null || $addressId === '') {
            return null;
        }

        $column = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $address = Address::query()
            ->whereKey((int) $addressId)
            ->where($column, $customerId)
            ->firstOrFail();

        return (int) $address->getKey();
    }

    /**
     * @param  list<array{product_id: int|string, quantity: int|float|string}>  $items
     * @return list<array{product_id: int, sku_snapshot: string, name_snapshot: string, quantity: float, unit_price: float, line_total: float}>
     */
    private function lineSnapshots(string $channel, int $storeId, int $customerId, array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = round((float) $item['quantity'], 3);

            $product = Product::query()
                ->forStore($storeId)
                ->whereKey($productId)
                ->where('products.is_active', true)
                ->firstOrFail();

            $catalogChannel = DB::table('catalogs')
                ->where('id', (int) $product->catalog_id)
                ->value('channel');
            abort_unless(strtolower((string) $catalogChannel) === $channel, 404);

            $storeProduct = DB::table('store_products')
                ->where('store_id', $storeId)
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->whereNotNull('price')
                ->first();

            abort_unless($storeProduct !== null, 422, 'The selected product is not active for this store.');

            $unitPrice = (float) $storeProduct->price;

            if ($channel === 'b2b') {
                $customer = B2bCustomer::query()->findOrFail($customerId);
                $pricing = $this->b2bPricing->resolve($customer, $storeId, $productId);
                if ($quantity < $pricing['minimum_quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ['A B2B item is below its minimum purchase quantity.'],
                    ]);
                }
                $unitPrice = $pricing['price'];
            }

            $lines[] = [
                'product_id' => $productId,
                'sku_snapshot' => (string) $product->sku,
                'name_snapshot' => (string) $product->name,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 3),
                'line_total' => round($quantity * $unitPrice, 3),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{line_total: float}>  $lines
     * @return array{subtotal: float, discount_total: float, delivery_total: float, grand_total: float}
     */
    private function totals(array $lines, float $discountTotal, float $deliveryTotal): array
    {
        $subtotal = round((float) collect($lines)->sum('line_total'), 3);
        $discountTotal = round(max(0.0, $discountTotal), 3);
        $deliveryTotal = round(max(0.0, $deliveryTotal), 3);

        if ($discountTotal > $subtotal + $deliveryTotal) {
            throw ValidationException::withMessages([
                'discount_total' => ['Discount cannot exceed the order amount.'],
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'delivery_total' => $deliveryTotal,
            'grand_total' => round($subtotal + $deliveryTotal - $discountTotal, 3),
        ];
    }

    private function paymentMethod(string $paymentMethod): string
    {
        $allowed = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if (! in_array($paymentMethod, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['The selected payment method is not configured.'],
            ]);
        }

        return $paymentMethod;
    }
}
