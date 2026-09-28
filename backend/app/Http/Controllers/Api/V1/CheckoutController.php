<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\B2bPriceResolver;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDomainResolver;
use App\Services\DashboardOperationalNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $auditLogger, DashboardOperationalNotifier $dashboardNotifier): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A valid Idempotency-Key header is required.'],
            ]);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = (int) $validated['store_id'];
        $resolver = app(CustomerDomainResolver::class);
        [$customer, $channel] = $resolver->forStore($user, $storeId);
        $legacyCustomerId = $resolver->legacyId($customer);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        $addressId = (int) $validated['address_id'];
        $paymentMethod = (string) ($validated['payment_method'] ?? config('checkout.default_payment_method'));
        $note = isset($validated['note']) ? (string) $validated['note'] : null;

        $allowedMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));

        if (! in_array($paymentMethod, $allowedMethods, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['The selected payment method is not configured.'],
            ]);
        }

        $requestHash = hash('sha256', json_encode([
            'store_id' => $storeId,
            'address_id' => $addressId,
            'payment_method' => $paymentMethod,
            'note' => $note,
        ], JSON_THROW_ON_ERROR));

        $address = Address::query()
            ->whereKey($addressId)
            ->where($customerColumn, $customer->getKey())
            ->firstOrFail();

        /** @var array{0: Order, 1: bool} $result */
        $result = DB::transaction(function () use (
            $customer,
            $legacyCustomerId,
            $customerColumn,
            $user,
            $address,
            $storeId,
            $channel,
            $paymentMethod,
            $note,
            $idempotencyKey,
            $requestHash,
            $auditLogger,
            $request,
        ): array {
            $existing = Order::query()
                ->where($customerColumn, $customer->getKey())
                ->where('checkout_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Order) {
                abort_if(
                    ! hash_equals((string) $existing->checkout_request_hash, $requestHash),
                    409,
                    'Idempotency key was already used for a different checkout request.',
                );

                return [$existing, false];
            }

            $cart = Cart::query()
                ->where('store_id', $storeId)
                ->where($customerColumn, $customer->getKey())
                ->where('channel', $channel)
                ->lockForUpdate()
                ->first();

            abort_unless($cart instanceof Cart, 409, 'No authenticated cart exists for this store.');

            $cartItems = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($cartItems->isEmpty(), 409, 'The cart is empty.');

            $lineSnapshots = [];
            $subtotal = 0.0;
            $reservations = [];

            foreach ($cartItems as $cartItem) {
                $product = Product::query()
                    ->forStore($storeId)
                    ->whereKey($cartItem->product_id)
                    ->where('products.is_active', true)
                    ->first();

                $storeProduct = DB::table('store_products')
                    ->where('store_id', $storeId)
                    ->where('product_id', $cartItem->product_id)
                    ->where('is_active', true)
                    ->whereNotNull('price')
                    ->first();

                abort_unless(
                    $product instanceof Product && $storeProduct !== null,
                    409,
                    'A cart item is no longer available.',
                );

                $quantity = (float) $cartItem->quantity;
                $unitPrice = (float) $storeProduct->price;
                if ($channel === 'b2b') {
                    abort_unless($customer instanceof B2bCustomer, 500);
                    $pricing = app(B2bPriceResolver::class)->resolve($customer, $storeId, (int) $cartItem->product_id);
                    abort_if($quantity < $pricing['minimum_quantity'], 409, 'Quantity is below the B2B minimum purchase quantity.');
                    $unitPrice = $pricing['price'];
                }
                $lineTotal = round($quantity * $unitPrice, 3);

                $inventoryRows = DB::table('inventories')
                    ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->where('warehouses.store_id', $storeId)
                    ->where('warehouses.is_active', true)
                    ->where('inventories.product_id', $cartItem->product_id)
                    ->orderBy('inventories.id')
                    ->lockForUpdate()
                    ->get();

                if ($inventoryRows->isNotEmpty()) {
                    $available = (float) $inventoryRows->sum(
                        static fn (object $row): float => max(
                            0.0,
                            (float) $row->quantity - (float) $row->reserved_quantity,
                        ),
                    );

                    abort_if($quantity > $available, 409, 'A cart item does not have enough stock.');

                    $remaining = $quantity;
                    foreach ($inventoryRows as $inventoryRow) {
                        $rowAvailable = max(
                            0.0,
                            (float) $inventoryRow->quantity - (float) $inventoryRow->reserved_quantity,
                        );
                        $reserve = min($remaining, $rowAvailable);

                        if ($reserve > 0) {
                            $reservations[] = [
                                'inventory_id' => (int) $inventoryRow->id,
                                'quantity' => $reserve,
                            ];
                            $remaining -= $reserve;
                        }

                        if ($remaining <= 0) {
                            break;
                        }
                    }
                }

                $lineSnapshots[] = [
                    'product_id' => (int) $product->getKey(),
                    'sku_snapshot' => (string) $product->sku,
                    'name_snapshot' => (string) $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
                $subtotal += $lineTotal;
            }

            $deliveryTotal = round((float) config('checkout.delivery_fee', 0), 3);
            $grandTotal = round($subtotal + $deliveryTotal, 3);

            $order = Order::query()->create([
                'store_id' => $storeId,
                'customer_id' => $legacyCustomerId,
                $customerColumn => $customer->getKey(),
                'address_id' => $address->getKey(),
                'order_number' => 'FDX-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                'channel' => $channel,
                'status' => 'pending',
                'currency' => 'EGP',
                'subtotal' => round($subtotal, 3),
                'discount_total' => 0,
                'delivery_total' => $deliveryTotal,
                'grand_total' => $grandTotal,
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_request_hash' => $requestHash,
                'payment_method' => $paymentMethod,
                'customer_note' => $note,
            ]);

            foreach ($lineSnapshots as $snapshot) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    ...$snapshot,
                ]);
            }

            foreach ($reservations as $reservation) {
                DB::table('inventories')
                    ->where('id', $reservation['inventory_id'])
                    ->increment('reserved_quantity', $reservation['quantity']);

                StockMovement::query()->create([
                    'inventory_id' => $reservation['inventory_id'],
                    'store_id' => $storeId,
                    'user_id' => $user->getKey(),
                    'type' => 'reserve',
                    'quantity' => $reservation['quantity'],
                    'reference_type' => 'order',
                    'reference_id' => $order->getKey(),
                    'reason' => 'checkout',
                ]);
            }

            Payment::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => null,
                'provider' => $paymentMethod,
                'provider_reference' => null,
                'status' => 'pending',
                'amount' => $grandTotal,
                'currency' => 'EGP',
                'metadata' => [
                    'method' => $paymentMethod,
                ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'store_id' => $storeId,
                'user_id' => $user->getKey(),
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'checkout',
            ]);

            $cart->delete();

            $auditLogger->record(
                'checkout.order_created',
                $user,
                $order,
                null,
                [
                    'order_number' => $order->order_number,
                    'store_id' => $storeId,
                    'channel' => $channel,
                    'grand_total' => $grandTotal,
                    'payment_method' => $paymentMethod,
                ],
                $request,
            );

            return [$order, true];
        }, 3);

        [$order, $created] = $result;

        if ($created) {
            $dashboardNotifier->orderCreated($order->fresh());
        }

        return response()->json(
            $this->orderPayload($order->fresh()),
            $created ? 201 : 200,
        );
    }

    private function orderPayload(Order $order): array
    {
        $items = OrderItem::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): array => [
                'id' => (int) $item->getKey(),
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku_snapshot,
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])
            ->values()
            ->all();

        $payment = Payment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'store_id' => (int) $order->store_id,
            'address_id' => $order->address_id === null ? null : (int) $order->address_id,
            'channel' => (string) $order->channel,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
            'grand_total' => (float) $order->grand_total,
            'payment_method' => $order->payment_method,
            'items' => $items,
            'payment' => $payment instanceof Payment ? [
                'id' => (int) $payment->getKey(),
                'provider' => (string) $payment->provider,
                'status' => (string) $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
            ] : null,
            'created_at' => $order->created_at?->toAtomString(),
        ];
    }
}
