<?php

namespace App\Services;

use App\Models\B2cCustomer;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\FlashReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FlashCheckoutService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommerceQuoteService $quotes,
        private readonly CommercialPolicyService $commercialPolicy,
        private readonly CustomerAddressService $addresses,
        private readonly CustomerDomainResolver $customers,
        private readonly DashboardOperationalNotifier $notifier,
        private readonly FlashOfferService $flash,
        private readonly InvoiceService $invoices,
        private readonly OrderDeliveryAddressSnapshotService $addressSnapshots,
    ) {}

    /** @return array{0:Order,1:bool} */
    public function confirmCustomer(
        Request $request,
        FlashReservation $reservation,
        User $user,
        int $storeId,
        int $addressId,
        string $paymentMethod,
        string $idempotencyKey,
    ): array {
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A valid Idempotency-Key header is required.'],
            ]);
        }

        $allowedMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if (! in_array($paymentMethod, $allowedMethods, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['The selected payment method is not configured.'],
            ]);
        }

        [$customer, $channel] = $this->customers->forStore($user, $storeId, $request);
        abort_unless($customer instanceof B2cCustomer && $channel === 'b2c', 409, 'Customer Flash checkout requires a retail customer context.');

        $legacyCustomerId = $this->customers->legacyId($customer);
        $address = $this->addresses->findOwned($user, $addressId, $customer, $channel);
        $requestHash = hash('sha256', json_encode([
            'reservation_id' => (string) $reservation->getKey(),
            'store_id' => $storeId,
            'address_id' => $addressId,
            'payment_method' => $paymentMethod,
        ], JSON_THROW_ON_ERROR));

        if ($this->flash->expireReservationIfDue((string) $reservation->getKey(), (int) $user->getKey())) {
            throw new HttpException(409, 'FLASH_RESERVATION_EXPIRED');
        }

        $result = DB::transaction(function () use (
            $request,
            $reservation,
            $user,
            $customer,
            $legacyCustomerId,
            $address,
            $storeId,
            $addressId,
            $paymentMethod,
            $idempotencyKey,
            $requestHash,
        ): array {
            $locked = FlashReservation::query()
                ->whereKey((string) $reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless((int) $locked->user_id === (int) $user->getKey(), 404);
            abort_unless((string) $locked->channel === 'customer', 409, 'FLASH_CHANNEL_NOT_ALLOWED');

            if ($locked->status === 'confirmed' && $locked->order_id !== null) {
                $existing = Order::query()->whereKey((int) $locked->order_id)->firstOrFail();
                abort_if(
                    (int) $existing->store_id !== $storeId
                    || (int) $existing->address_id !== $addressId
                    || (string) $existing->payment_method !== $paymentMethod,
                    409,
                    'Flash reservation is already attached to a different checkout request.',
                );

                return [$existing, false];
            }

            abort_unless($locked->status === 'active', 409, 'Flash reservation is not active.');
            abort_if($locked->expiresAt()->isPast(), 409, 'FLASH_RESERVATION_EXPIRED');

            $existing = Order::query()
                ->where('b2c_customer_id', $customer->getKey())
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

            $offerProduct = FlashOfferProduct::query()
                ->whereKey((int) $locked->flash_offer_product_id)
                ->firstOrFail();
            $offer = FlashOffer::query()
                ->whereKey((int) $locked->flash_offer_id)
                ->firstOrFail();

            abort_unless((int) $offer->store_id === $storeId, 409, 'Flash reservation store mismatch.');

            $product = DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->join('store_products', function ($join) use ($storeId): void {
                    $join->on('store_products.product_id', '=', 'products.id')
                        ->where('store_products.store_id', '=', $storeId);
                })
                ->where('products.id', (int) $offerProduct->product_id)
                ->where('products.is_active', true)
                ->where('catalogs.store_id', $storeId)
                ->where('catalogs.channel', 'b2c')
                ->where('catalogs.is_active', true)
                ->where('catalogs.is_migration_quarantine', false)
                ->where('store_products.is_active', true)
                ->whereNotNull('store_products.price')
                ->first([
                    'products.id',
                    'products.sku',
                    'products.name',
                    'store_products.price',
                ]);

            abort_unless($product !== null, 409, 'FLASH_PRODUCT_UNAVAILABLE');

            $sellingQuantity = round((float) $locked->selling_quantity, 3);
            $baseQuantity = round((float) $locked->reserved_base_quantity, 3);
            $conversionFactor = $sellingQuantity > 0
                ? round($baseQuantity / $sellingQuantity, 3)
                : (float) $offerProduct->conversion_factor;
            $flashUnitPrice = round((float) $locked->unit_price, 3);

            $sellingUnit = DB::table('product_selling_units')
                ->where('product_id', (int) $offerProduct->product_id)
                ->when(
                    $offerProduct->selling_unit_id !== null,
                    fn ($query) => $query->where('id', (int) $offerProduct->selling_unit_id),
                    fn ($query) => $query->where('code', (string) $offerProduct->selling_unit_code),
                )
                ->where('is_active', true)
                ->first();

            $sellingUnitCode = (string) ($sellingUnit->code ?? $offerProduct->selling_unit_code ?? 'BASE');
            $sellingUnitName = (string) ($sellingUnit->name ?? $sellingUnitCode);
            $sellingUnitSku = $sellingUnit->sku ?? $product->sku;
            $sellingUnitBarcode = $sellingUnit->barcode ?? null;
            $normalUnitPrice = $sellingUnit !== null && $sellingUnit->price !== null
                ? (float) $sellingUnit->price
                : round((float) $product->price, 3);

            $normalSubtotal = round($normalUnitPrice * $sellingQuantity, 3);
            $flashSubtotal = round($flashUnitPrice * $sellingQuantity, 3);
            $flashDiscount = round(max(0.0, $normalSubtotal - $flashSubtotal), 3);
            $totals = $this->quotes->directPurchaseTotals($storeId, $flashSubtotal);

            $decision = $this->commercialPolicy->evaluate(
                (int) $offerProduct->product_id,
                $legacyCustomerId,
                'customer',
                $baseQuantity,
            );
            if (! $decision['allowed']) {
                throw ValidationException::withMessages([
                    'flash_reservation' => [
                        'Commercial policy rejected Flash checkout: '.implode(',', $decision['reason_codes']),
                    ],
                ]);
            }

            $order = Order::query()->create([
                'store_id' => $storeId,
                'customer_id' => $legacyCustomerId,
                'b2c_customer_id' => $customer->getKey(),
                'address_id' => $address->getKey(),
                ...$this->addressSnapshots->attributes($address),
                'order_number' => 'FDX-FLASH-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'channel' => 'b2c',
                'status' => 'pending',
                'currency' => $totals['currency'],
                'subtotal' => $normalSubtotal,
                'discount_total' => $flashDiscount,
                'delivery_total' => $totals['delivery_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'quote_id' => 'FLASH-'.(string) $locked->getKey(),
                'quoted_at' => now(),
                'pricing_snapshot' => [
                    'source' => 'flash_offer_v1',
                    'flash_offer' => [
                        'id' => (int) $offer->getKey(),
                        'offer_product_id' => (int) $offerProduct->getKey(),
                        'reservation_id' => (string) $locked->getKey(),
                        'type' => 'flash',
                        'normal_unit_price' => $normalUnitPrice,
                        'flash_unit_price' => $flashUnitPrice,
                        'flash_discount_total' => $flashDiscount,
                        'counts_toward_normal_quota' => (bool) $offer->counts_toward_normal_quota,
                    ],
                    'tax_rate' => $totals['tax_rate'],
                ],
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_request_hash' => $requestHash,
                'payment_method' => $paymentMethod,
            ]);

            OrderItem::query()->create([
                'order_id' => $order->getKey(),
                'product_id' => (int) $offerProduct->product_id,
                'sku_snapshot' => (string) $product->sku,
                'name_snapshot' => (string) $product->name,
                'quantity' => $sellingQuantity,
                'quantity_conversion_factor' => $conversionFactor,
                'base_unit_price_snapshot' => round($flashUnitPrice / max($conversionFactor, 0.001), 3),
                'unit_price' => $flashUnitPrice,
                'line_discount_total' => $flashDiscount,
                'line_tax_total' => $totals['tax_total'],
                'line_total' => $flashSubtotal,
                'currency' => $totals['currency'],
                'selling_unit_code_snapshot' => $sellingUnitCode,
                'selling_unit_name_snapshot' => $sellingUnitName,
                'selling_unit_quantity' => $sellingQuantity,
                'base_quantity' => $baseQuantity,
                'conversion_factor_snapshot' => $conversionFactor,
                'selling_unit_sku_snapshot' => $sellingUnitSku,
                'selling_unit_barcode_snapshot' => $sellingUnitBarcode,
            ]);

            if ((bool) $offer->counts_toward_normal_quota) {
                try {
                    $this->commercialPolicy->reserveBaseQuantityForOrder(
                        (int) $order->getKey(),
                        $legacyCustomerId,
                        (int) $offerProduct->product_id,
                        $baseQuantity,
                        'customer',
                    );
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages([
                        'flash_reservation' => [
                            'Commercial policy rejected Flash checkout: '.$exception->getMessage(),
                        ],
                    ]);
                }
            }

            foreach ($locked->inventoryAllocations() as $allocation) {
                $inventoryId = (int) ($allocation['inventory_id'] ?? 0);
                $quantity = (float) ($allocation['quantity'] ?? 0);
                if ($inventoryId <= 0 || $quantity <= 0) {
                    continue;
                }

                StockMovement::query()->create([
                    'inventory_id' => $inventoryId,
                    'store_id' => $storeId,
                    'user_id' => $user->getKey(),
                    'type' => 'reserve',
                    'quantity' => $quantity,
                    'reference_type' => 'order',
                    'reference_id' => $order->getKey(),
                    'reason' => 'flash_checkout_claim',
                ]);
            }

            Payment::query()->create([
                'order_id' => $order->getKey(),
                'invoice_id' => null,
                'provider' => $paymentMethod,
                'provider_reference' => null,
                'status' => 'pending',
                'amount' => (float) $totals['grand_total'],
                'currency' => (string) $totals['currency'],
                'metadata' => [
                    'method' => $paymentMethod,
                    'flash_offer_id' => (int) $offer->getKey(),
                    'flash_reservation_id' => (string) $locked->getKey(),
                ],
            ]);

            $this->invoices->issueForOrder($order, $user);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'store_id' => $storeId,
                'user_id' => $user->getKey(),
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'flash_checkout',
            ]);

            $this->flash->confirm(
                (string) $locked->getKey(),
                (int) $user->getKey(),
                (int) $order->getKey(),
            );

            $this->audit->record(
                'flash.checkout.order_created',
                $user,
                $order,
                null,
                [
                    'store_id' => $storeId,
                    'flash_offer_id' => (int) $offer->getKey(),
                    'flash_offer_product_id' => (int) $offerProduct->getKey(),
                    'flash_reservation_id' => (string) $locked->getKey(),
                    'selling_unit' => $sellingUnitCode,
                    'selling_quantity' => $sellingQuantity,
                    'base_quantity' => $baseQuantity,
                    'flash_unit_price' => $flashUnitPrice,
                    'grand_total' => (float) $totals['grand_total'],
                ],
                $request,
            );

            return [$order->fresh(), true];
        }, 3);

        [$order, $created] = $result;
        if ($created) {
            $this->notifier->orderCreated($order);
        }

        return [$order, $created];
    }
}
