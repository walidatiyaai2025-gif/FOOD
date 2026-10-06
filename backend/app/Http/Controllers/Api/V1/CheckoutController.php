<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MarketingCoupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\B2bAccountLedgerService;
use App\Services\CommerceQuoteService;
use App\Services\CommercialPolicyService;
use App\Services\CouponRedemptionService;
use App\Services\CustomerAddressService;
use App\Services\CustomerDomainResolver;
use App\Services\DashboardOperationalNotifier;
use App\Services\InvoiceService;
use App\Services\OrderDeliveryAddressSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __invoke(
        Request $request,
        AuditLogger $auditLogger,
        DashboardOperationalNotifier $dashboardNotifier,
        CommerceQuoteService $quotes,
        CommercialPolicyService $commercialPolicy,
        InvoiceService $invoices,
    ): JsonResponse {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'requested_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
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
        [$customer, $channel] = $resolver->forStore($user, $storeId, $request);
        $legacyCustomerId = $resolver->legacyId($customer);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        $addressId = (int) $validated['address_id'];
        $paymentMethod = (string) ($validated['payment_method'] ?? config('checkout.default_payment_method'));
        $note = isset($validated['note']) ? (string) $validated['note'] : null;
        $requestedDeliveryDate = $validated['requested_delivery_date'] ?? null;
        $couponCode = isset($validated['coupon_code']) && trim((string) $validated['coupon_code']) !== ''
            ? strtoupper(trim((string) $validated['coupon_code']))
            : null;

        $allowedMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if ($channel === 'b2b') {
            abort_unless($customer instanceof B2bCustomer, 500);
            $account = B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->firstOrFail();

            $finance = app(B2bAccountLedgerService::class)->summary($customer, $storeId);
            if ((float) $finance['purchasing_power'] > 0 && ! in_array('account_credit', $allowedMethods, true)) {
                $allowedMethods[] = 'account_credit';
            }
        }

        if (! in_array($paymentMethod, $allowedMethods, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['The selected payment method is not configured.'],
            ]);
        }

        $requestHash = hash('sha256', json_encode([
            'store_id' => $storeId,
            'address_id' => $addressId,
            'payment_method' => $paymentMethod,
            'requested_delivery_date' => $requestedDeliveryDate,
            'note' => $note,
            'coupon_code' => $couponCode,
        ], JSON_THROW_ON_ERROR));

        $address = app(CustomerAddressService::class)
            ->findOwned($user, $addressId, $customer, $channel);

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
            $requestedDeliveryDate,
            $note,
            $idempotencyKey,
            $requestHash,
            $couponCode,
            $auditLogger,
            $request,
            $quotes,
            $commercialPolicy,
            $invoices,
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

            $lockedItems = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($lockedItems->isEmpty(), 409, 'The cart is empty.');

            // This is the authoritative reprice point. No submitted or stale cart price is trusted.
            $quote = $quotes->quoteCart(
                $cart,
                $user,
                $couponCode,
                $paymentMethod,
                true,
            );

            if ($channel === 'b2b' && $paymentMethod === 'account_credit') {
                abort_unless($customer instanceof B2bCustomer, 500);

                // Serialize credit-backed B2B checkout against the account row, then
                // recompute the authoritative ledger exposure inside this transaction.
                B2bAccount::query()
                    ->where('b2b_customer_id', $customer->getKey())
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();

                $financeAtSubmit = app(B2bAccountLedgerService::class)->summary($customer, $storeId);
                abort_if(
                    (float) $quote['grand_total'] > (float) $financeAtSubmit['purchasing_power'] + 0.0001,
                    409,
                    'Account credit is insufficient for the authoritative order total.',
                );
            }

            $currency = (string) $quote['currency'];
            $lineSnapshots = array_map(
                fn (array $line): array => $quotes->orderLineSnapshot($line, $currency),
                $quote['items'],
            );
            $headerSnapshot = $quotes->orderHeaderSnapshot($quote);

            // Lock and allocate inventory only after the final quote so stock and price are
            // both revalidated inside the same checkout transaction.
            $reservations = [];
            foreach ($lineSnapshots as $snapshot) {
                $inventoryRows = DB::table('inventories')
                    ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->where('warehouses.store_id', $storeId)
                    ->where('warehouses.is_active', true)
                    ->where('inventories.product_id', $snapshot['product_id'])
                    ->orderBy('inventories.id')
                    ->lockForUpdate()
                    ->get();

                if ($inventoryRows->isEmpty()) {
                    continue;
                }

                $available = (float) $inventoryRows->sum(
                    static fn (object $row): float => max(
                        0.0,
                        (float) $row->quantity - (float) $row->reserved_quantity,
                    ),
                );

                abort_if(
                    (float) $snapshot['quantity'] > $available + 0.0001,
                    409,
                    'A cart item does not have enough stock.',
                );

                $remaining = (float) $snapshot['quantity'];
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

                    if ($remaining <= 0.0001) {
                        break;
                    }
                }
            }

            $order = Order::query()->create([
                'store_id' => $storeId,
                'customer_id' => $legacyCustomerId,
                $customerColumn => $customer->getKey(),
                'address_id' => $address->getKey(),
                ...app(OrderDeliveryAddressSnapshotService::class)->attributes($address),
                'requested_delivery_date' => $requestedDeliveryDate,
                'order_number' => 'FDX-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                'channel' => $channel,
                'status' => 'pending',
                'currency' => $headerSnapshot['currency'],
                'subtotal' => $headerSnapshot['subtotal'],
                'discount_total' => $headerSnapshot['discount_total'],
                'delivery_total' => $headerSnapshot['delivery_total'],
                'tax_total' => $headerSnapshot['tax_total'],
                'grand_total' => $headerSnapshot['grand_total'],
                'quote_id' => $headerSnapshot['quote_id'],
                'quoted_at' => $headerSnapshot['quoted_at'],
                'b2b_account_id_snapshot' => $headerSnapshot['b2b_account_id_snapshot'],
                'price_tier_id_snapshot' => $headerSnapshot['price_tier_id_snapshot'],
                'price_tier_code_snapshot' => $headerSnapshot['price_tier_code_snapshot'],
                'pricing_snapshot' => $headerSnapshot['pricing_snapshot'],
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_request_hash' => $requestHash,
                'payment_method' => $paymentMethod,
                'customer_note' => $note,
            ]);

            foreach ($lineSnapshots as $snapshot) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    ...$snapshot,
                    'selling_unit_quantity' => $snapshot['quantity'],
                    'base_quantity' => $snapshot['quantity'],
                    'conversion_factor_snapshot' => 1,
                ]);

                try {
                    $commercialPolicy->reserveBaseQuantityForOrder(
                        (int) $order->getKey(),
                        $legacyCustomerId,
                        (int) $snapshot['product_id'],
                        (float) $snapshot['quantity'],
                        'customer',
                    );
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Commercial policy rejected product '.$snapshot['product_id'].': '.$exception->getMessage(),
                        ],
                    ]);
                }
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
                'amount' => (float) $quote['grand_total'],
                'currency' => $currency,
                'metadata' => [
                    'method' => $paymentMethod,
                    'quote_id' => $quote['quote_id'],
                ],
            ]);

            // Invoice issuance is part of the same checkout transaction. It snapshots the
            // already-authoritative order/order-item pricing and links the payment.
            $invoices->issueForOrder($order, $user);

            $couponId = $quote['coupon']['id'] ?? null;
            if ($couponId !== null) {
                $coupon = MarketingCoupon::query()->find((int) $couponId);
                if ($coupon instanceof MarketingCoupon) {
                    app(CouponRedemptionService::class)->redeem(
                        $coupon,
                        $user,
                        $order,
                        (float) $quote['subtotal'] + (float) $quote['delivery_total'],
                        (float) $quote['coupon_discount_total'],
                    );
                }
            }

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
                    'quote_id' => $quote['quote_id'],
                    'grand_total' => $quote['grand_total'],
                    'tax_total' => $quote['tax_total'],
                    'payment_method' => $paymentMethod,
                    'coupon_code' => $couponCode,
                    'discount_total' => $quote['discount_total'],
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
                'quantity_conversion_factor' => (float) ($item->quantity_conversion_factor ?? 1),
                'base_unit_price' => $item->base_unit_price_snapshot === null ? null : (float) $item->base_unit_price_snapshot,
                'unit_price' => (float) $item->unit_price,
                'discount_total' => (float) ($item->line_discount_total ?? 0),
                'tax_total' => (float) ($item->line_tax_total ?? 0),
                'line_total' => (float) $item->line_total,
                'currency' => (string) ($item->currency ?? $order->currency),
                'price_tier_id' => $item->price_tier_id_snapshot === null ? null : (int) $item->price_tier_id_snapshot,
                'price_tier_code' => $item->price_tier_code_snapshot,
                'minimum_quantity' => $item->minimum_quantity_snapshot === null ? null : (float) $item->minimum_quantity_snapshot,
                'ordering_increment' => $item->ordering_increment_snapshot === null ? null : (float) $item->ordering_increment_snapshot,
                'pack_size' => $item->pack_size_snapshot === null ? null : (float) $item->pack_size_snapshot,
                'case_size' => $item->case_size_snapshot === null ? null : (float) $item->case_size_snapshot,
            ])
            ->values()
            ->all();

        $payment = Payment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();
        $invoice = DB::table('invoices')
            ->where('order_id', $order->getKey())
            ->whereIn('status', ['issued', 'reissued'])
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->first(['id', 'invoice_number', 'status']);

        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'store_id' => (int) $order->store_id,
            'address_id' => $order->address_id === null ? null : (int) $order->address_id,
            'delivery_address' => app(OrderDeliveryAddressSnapshotService::class)->payload($order),
            'requested_delivery_date' => $order->requested_delivery_date,
            'channel' => (string) $order->channel,
            'status' => (string) $order->status,
            'quote_id' => $order->quote_id,
            'quoted_at' => $order->quoted_at?->toAtomString(),
            'currency' => (string) $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'delivery_total' => (float) $order->delivery_total,
            'tax_total' => (float) ($order->tax_total ?? 0),
            'grand_total' => (float) $order->grand_total,
            'price_tier_id' => $order->price_tier_id_snapshot === null ? null : (int) $order->price_tier_id_snapshot,
            'price_tier_code' => $order->price_tier_code_snapshot,
            'payment_method' => $order->payment_method,
            'items' => $items,
            'payment' => $payment instanceof Payment ? [
                'id' => (int) $payment->getKey(),
                'provider' => (string) $payment->provider,
                'status' => (string) $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
            ] : null,
            'invoice' => $invoice === null ? null : [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'status' => (string) $invoice->status,
            ],
            'created_at' => $order->created_at?->toAtomString(),
        ];
    }
}
