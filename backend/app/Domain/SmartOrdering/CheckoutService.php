<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Models\MarketingCoupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\SmartOrderingCart;
use App\Models\SmartOrderingOrderContext;
use App\Models\SmartOrderingOrderEvent;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\CouponRedemptionService;
use App\Services\CommerceQuoteService;
use App\Services\CommercialPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly BranchPolicyService $branches,
        private readonly CommerceQuoteService $quotes,
        private readonly B2cCustomerService $customers,
        private readonly CommercialPolicyService $commercialPolicy,
        private readonly InventoryService $inventory,
        private readonly OrderNotificationBridge $notifications,
        private readonly SmartOrderingMerchandisingService $merchandising,
        private readonly CouponRedemptionService $coupons,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{order:Order,context:SmartOrderingOrderContext,replayed:bool}
     */
    public function checkout(
        SmartOrderingCart $cart,
        string $idempotencyKey,
        array $payload,
        ?User $authenticatedUser = null,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Idempotency-Key must be between 16 and 100 characters.'],
            ]);
        }

        $branch = $this->carts->branchForCart($cart);
        $mode = strtolower(trim((string) ($payload['fulfillment_mode'] ?? '')));

        $idempotencyHash = hash('sha256', $branch->getKey().'|'.$idempotencyKey);
        $requestHash = hash('sha256', json_encode([
            'cart' => (string) $cart->public_id,
            'mode' => $mode,
            'scheduled_for' => isset($payload['scheduled_for']) ? trim((string) $payload['scheduled_for']) : null,
            'contact' => [
                'name' => trim((string) ($payload['name'] ?? '')),
                'phone' => trim((string) ($payload['phone'] ?? '')),
                'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
            ],
            'table_public_id' => $payload['table_public_id'] ?? null,
            'delivery' => [
                'address' => $payload['delivery_address'] ?? null,
                'latitude' => $payload['delivery_latitude'] ?? null,
                'longitude' => $payload['delivery_longitude'] ?? null,
            ],
            'order_note' => isset($payload['order_note']) ? trim((string) $payload['order_note']) : null,
            'coupon_code' => strtoupper(trim((string) ($payload['coupon_code'] ?? ''))),
            'authenticated_user_id' => $authenticatedUser?->getKey(),
        ], JSON_THROW_ON_ERROR));

        $existing = SmartOrderingOrderContext::query()
            ->where('idempotency_key_hash', $idempotencyHash)
            ->first();
        if ($existing instanceof SmartOrderingOrderContext) {
            abort_unless(
                hash_equals((string) $existing->request_hash, $requestHash),
                409,
                'Idempotency-Key was already used for a different checkout request.',
            );

            return [
                'order' => Order::query()->findOrFail((int) $existing->order_id),
                'context' => $existing,
                'replayed' => true,
            ];
        }

        $scheduled = $this->branches->scheduledFor(
            $branch,
            $mode,
            isset($payload['scheduled_for']) ? (string) $payload['scheduled_for'] : null,
        );

        abort_unless((string) $cart->status === 'active', 409, 'Cart is no longer active.');

        $table = null;
        $zone = null;
        if ($mode === 'dine_in') {
            $tablePublicId = trim((string) ($payload['table_public_id'] ?? ''));
            $tableToken = trim((string) ($payload['table_token'] ?? ''));
            abort_if($tablePublicId === '' || $tableToken === '', 422, 'Dining table context is required.');
            $table = $this->branches->table($branch, $tablePublicId, $tableToken);
        } elseif ($mode === 'delivery') {
            abort_unless(isset($payload['delivery_latitude'], $payload['delivery_longitude']), 422, 'Delivery location is required.');
            $zone = $this->branches->deliveryZone(
                $branch,
                (float) $payload['delivery_latitude'],
                (float) $payload['delivery_longitude'],
            );
            abort_if(trim((string) ($payload['delivery_address'] ?? '')) === '', 422, 'Delivery address is required.');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        abort_if($name === '' || $phone === '', 422, 'Customer name and phone are required.');

        $result = DB::transaction(function () use (
            $cart,
            $branch,
            $mode,
            $scheduled,
            $payload,
            $table,
            $zone,
            $name,
            $phone,
            $email,
            $idempotencyKey,
            $idempotencyHash,
            $requestHash,
            $authenticatedUser,
        ): array {
            $lockedCart = SmartOrderingCart::query()->whereKey($cart->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((string) $lockedCart->status === 'active', 409, 'Cart is no longer active.');

            $customer = $this->customers->create((int) $branch->store_id, [
                'name' => $name,
                'phone' => $phone,
                'email' => $email === '' ? null : $email,
            ], $authenticatedUser);
            abort_unless($customer->legacy_customer_id !== null, 500, 'Customer compatibility identity is missing.');

            $pricingLines = $this->carts->pricingLines($lockedCart);
            abort_if($pricingLines === [], 409, 'The cart is empty.');

            $deliveryGross = $mode === 'delivery' && $zone !== null
                ? round((float) $zone->delivery_fee, 3)
                : 0.0;
            $couponCode = trim((string) ($payload['coupon_code'] ?? ''));
            $quote = $this->quotes->quote(
                'b2c',
                (int) $branch->store_id,
                $customer,
                $pricingLines,
                $authenticatedUser,
                $couponCode === '' ? null : $couponCode,
                'cash_on_delivery',
                true,
                null,
                $deliveryGross,
                false,
            );

            $site = $branch->site()->firstOrFail();
            $merchandising = $this->merchandising->decorateQuote(
                $site,
                $branch,
                $pricingLines,
                $quote,
                (int) $customer->legacy_customer_id,
                $lockedCart->merchandisingContext(),
                $deliveryGross,
                true,
            );
            $quote = $merchandising['quote'];

            $subtotal = round((float) $quote['subtotal'], 3);
            $discount = round(min($subtotal, max(0.0, (float) $quote['discount_total'])), 3);
            $delivery = round(max(
                0.0,
                $deliveryGross - max(0.0, (float) ($quote['delivery_discount_total'] ?? 0)),
            ), 3);
            $service = round(max(0.0, (float) $branch->service_charge), 3);

            if ($mode === 'delivery' && $zone !== null) {
                abort_if($subtotal + 0.0001 < (float) $zone->minimum_order, 409, 'Order does not meet the delivery-zone minimum.');
            }

            $taxRate = max(0.0, (float) $quote['tax_rate']);
            $taxable = max(0.0, $subtotal - $discount) + $delivery + $service;
            $tax = round($taxable * ($taxRate / 100), 3);
            $grand = round($taxable + $tax, 3);
            $currency = (string) $quote['currency'];

            $order = Order::query()->create([
                'store_id' => (int) $branch->store_id,
                'customer_id' => (int) $customer->legacy_customer_id,
                'b2c_customer_id' => (int) $customer->getKey(),
                'address_id' => null,
                'delivery_address_snapshot' => $mode === 'delivery'
                    ? [
                        'version' => 1,
                        'label' => 'Smart Ordering delivery',
                        'line1' => (string) $payload['delivery_address'],
                        'country_code' => 'KW',
                    ]
                    : null,
                'delivery_latitude' => $mode === 'delivery' ? (float) $payload['delivery_latitude'] : null,
                'delivery_longitude' => $mode === 'delivery' ? (float) $payload['delivery_longitude'] : null,
                'order_number' => 'FDX-SO-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'channel' => 'b2c',
                'status' => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'delivery_total' => $delivery,
                'tax_total' => $tax,
                'grand_total' => $grand,
                'quote_id' => (string) $quote['quote_id'],
                'quoted_at' => (string) $quote['quoted_at'],
                'pricing_snapshot' => [
                    'source' => 'smart_ordering_backend_v1',
                    'quote_id' => $quote['quote_id'],
                    'tax_rate' => $taxRate,
                    'service_charge_total' => $service,
                    'branch_public_id' => (string) $branch->public_id,
                    'fulfillment_mode' => $mode,
                    'promotion' => $quote['promotion'] ?? null,
                    'coupon' => $quote['coupon'] ?? null,
                    'merchandising' => $quote['merchandising'] ?? null,
                    'delivery_discount_total' => (float) ($quote['delivery_discount_total'] ?? 0),
                ],
                'payment_method' => 'cash_on_delivery',
                'customer_note' => isset($payload['order_note']) ? mb_substr(trim((string) $payload['order_note']), 0, 1000) : null,
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_request_hash' => $requestHash,
            ]);

            $snapshots = [];
            foreach ($quote['items'] as $line) {
                $snapshot = $this->quotes->orderLineSnapshot($line, $currency);
                $snapshots[] = $snapshot;
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    ...$snapshot,
                    'selling_unit_quantity' => $snapshot['quantity'],
                    'base_quantity' => $snapshot['quantity'],
                    'conversion_factor_snapshot' => 1,
                ]);
            }

            foreach ($this->aggregate($pricingLines) as $productId => $quantity) {
                try {
                    $this->commercialPolicy->reserveBaseQuantityForOrder(
                        (int) $order->getKey(),
                        (int) $customer->legacy_customer_id,
                        $productId,
                        $quantity,
                        'customer',
                    );
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages(['items' => [$exception->getMessage()]]);
                }
            }

            $this->inventory->reserve($order, $pricingLines);

            if (is_array($quote['coupon'] ?? null) && isset($quote['coupon']['id'])) {
                $couponUser = $authenticatedUser;
                if (! $couponUser instanceof User && $customer->user_id !== null) {
                    $couponUser = User::query()->find((int) $customer->user_id);
                }
                abort_unless($couponUser instanceof User, 422, 'An authenticated customer is required to redeem a coupon.');

                $coupon = MarketingCoupon::query()
                    ->whereKey((int) $quote['coupon']['id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->coupons->redeem(
                    $coupon,
                    $couponUser,
                    $order,
                    $subtotal + $deliveryGross,
                    (float) ($quote['coupon_discount_total'] ?? 0),
                );
            }

            $this->merchandising->commitRedemptions(
                $site,
                $order,
                $merchandising['applied'],
                (int) $customer->legacy_customer_id,
                (string) $lockedCart->public_id,
            );

            Payment::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => null,
                'provider' => 'cash_on_delivery',
                'provider_reference' => null,
                'status' => 'pending',
                'amount' => $grand,
                'currency' => $currency,
                'metadata' => [
                    'method' => 'cash_on_delivery',
                    'source' => 'smart_ordering',
                    'public_branch_id' => (string) $branch->public_id,
                ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'store_id' => (int) $branch->store_id,
                'user_id' => null,
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'smart_ordering_created',
            ]);

            $context = SmartOrderingOrderContext::query()->create([
                'order_id' => $order->getKey(),
                'public_reference' => (string) Str::uuid(),
                'branch_id' => $branch->getKey(),
                'cart_id' => $lockedCart->getKey(),
                'revision_public_id' => $lockedCart->revision_public_id,
                'fulfillment_mode' => $mode,
                'table_id' => $table?->id,
                'delivery_zone_id' => $zone?->id,
                'scheduled_for' => $scheduled->utc(),
                'delivery_address' => $mode === 'delivery' ? ['line1' => (string) $payload['delivery_address']] : null,
                'delivery_latitude' => $mode === 'delivery' ? (float) $payload['delivery_latitude'] : null,
                'delivery_longitude' => $mode === 'delivery' ? (float) $payload['delivery_longitude'] : null,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'delivery_total' => $delivery,
                'service_charge_total' => $service,
                'tax_total' => $tax,
                'grand_total' => $grand,
                'lifecycle_status' => 'NEW',
                'idempotency_key_hash' => $idempotencyHash,
                'request_hash' => $requestHash,
                'cart_snapshot' => $this->carts->cartSnapshot($lockedCart),
                'notification_status' => 'pending',
            ]);

            SmartOrderingOrderEvent::query()->create([
                'order_context_id' => $context->getKey(),
                'user_id' => null,
                'from_status' => null,
                'to_status' => 'NEW',
                'event_key' => 'created',
                'note' => 'smart_ordering_created',
                'metadata' => ['order_number' => (string) $order->order_number],
            ]);

            $lockedCart->update([
                'status' => 'checked_out',
                'fulfillment_mode' => $mode,
            ]);

            return ['order' => $order, 'context' => $context, 'replayed' => false];
        }, 3);

        try {
            $this->notifications->notify($result['order'], $result['context']);
            $result['context']->update(['notification_status' => 'sent', 'notification_error' => null]);
        } catch (Throwable $exception) {
            $result['context']->update([
                'notification_status' => 'failed',
                'notification_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }

        $result['context']->refresh();

        return $result;
    }

    /**
     * @param  list<array{product_id:int,quantity:float}>  $items
     * @return array<int,float>
     */
    private function aggregate(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $totals[$id] = round(($totals[$id] ?? 0.0) + (float) $item['quantity'], 3);
        }

        return $totals;
    }
}
