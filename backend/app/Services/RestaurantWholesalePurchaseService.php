<?php

namespace App\Services;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RestaurantWholesalePurchaseService
{
    public function __construct(
        private readonly SmartOrderingEnterpriseEntitlementBridge $restaurantAccess,
        private readonly CustomerDomainResolver $customers,
        private readonly WholesalePrincipal $principal,
        private readonly CommerceQuoteService $quotes,
        private readonly CustomerAddressService $addresses,
        private readonly B2bAccountLedgerService $ledger,
    ) {}

    /**
     * Resolve one restaurant buyer context without granting any B2B-admin authority.
     *
     * @return array{
     *   restaurant:Store,
     *   restaurant_store_id:int,
     *   restaurant_stores:Collection<int,Store>,
     *   customer:B2bCustomer,
     *   account:B2bAccount,
     *   wholesale_store_id:int
     * }
     */
    public function context(Request $request, User $user): array
    {
        $restaurants = $this->restaurantStores($user);
        abort_if($restaurants->isEmpty(), 404, 'Restaurant Wholesale Purchases is not available.');

        $requested = (int) (
            $request->input('retail_store_id')
            ?? $request->query('retail_store_id')
            ?? 0
        );

        if ($requested > 0) {
            abort_unless($restaurants->contains(fn (Store $store): bool => (int) $store->getKey() === $requested), 404);
            $restaurant = $restaurants->first(fn (Store $store): bool => (int) $store->getKey() === $requested);
        } else {
            $restaurant = $restaurants->first();
        }

        abort_unless($restaurant instanceof Store, 404);
        $restaurantStoreId = (int) $restaurant->getKey();

        // The existing resolver is the canonical Retail Store -> B2B buyer-account authority.
        $request->merge(['retail_store_id' => $restaurantStoreId]);
        $customer = $this->customers->b2bFromRequest($user, $request);

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();
        abort_unless($account instanceof B2bAccount, 403, 'Approved Wholesale buyer account is required.');

        return [
            'restaurant' => $restaurant,
            'restaurant_store_id' => $restaurantStoreId,
            'restaurant_stores' => $restaurants,
            'customer' => $customer,
            'account' => $account,
            'wholesale_store_id' => $this->principal->storeId(),
        ];
    }

    /** @return Collection<int,Store> */
    public function restaurantStores(User $user): Collection
    {
        $eligibleIds = app(RetailMerchantIdentityService::class)->wholesaleEntitledRetailStoreIds($user);
        if ($eligibleIds === []) {
            return collect();
        }

        $query = Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $eligibleIds)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C');

        // #2168 is landing independently. Use its persisted profile as soon as
        // it exists; before then the Restaurant SaaS master entitlement remains
        // the authoritative compatibility signal.
        if (Schema::hasColumn('stores', 'business_profile')) {
            $query->where('stores.business_profile', 'RESTAURANT');
        } else {
            $restaurantIds = $this->restaurantAccess->enabledStoreIds($eligibleIds);
            if ($restaurantIds === []) {
                return collect();
            }

            $query->whereIn('stores.id', $restaurantIds);
        }

        return $query->orderBy('stores.name')->get();
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     */
    public function cart(array $context, bool $create = false): ?Cart
    {
        $query = Cart::query()
            ->where('store_id', $context['wholesale_store_id'])
            ->where('b2b_customer_id', $context['customer']->getKey())
            ->where('channel', 'b2b');

        $cart = $query->first();
        if ($cart instanceof Cart || ! $create) {
            return $cart;
        }

        return Cart::query()->firstOrCreate(
            [
                'store_id' => $context['wholesale_store_id'],
                'b2b_customer_id' => $context['customer']->getKey(),
                'channel' => 'b2b',
            ],
            [
                'customer_id' => $this->customers->legacyId($context['customer']),
                'b2c_customer_id' => null,
                'guest_token' => null,
            ],
        );
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     * @return array<string,mixed>|null
     */
    public function cartSnapshot(array $context, ?User $user = null): ?array
    {
        $cart = $this->cart($context);
        if (! $cart instanceof Cart) {
            return null;
        }

        return $this->quotes->quoteCart($cart, $user, null, null, false);
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     */
    public function addCartItem(array $context, User $user, int $productId, float $quantity): void
    {
        DB::transaction(function () use ($context, $user, $productId, $quantity): void {
            $cart = $this->cart($context, true);
            abort_unless($cart instanceof Cart, 409);

            $item = CartItem::query()->firstOrNew([
                'cart_id' => $cart->getKey(),
                'product_id' => $productId,
            ]);
            $item->quantity = ($item->exists ? (float) $item->quantity : 0.0) + $quantity;
            $item->save();

            // Strict canonical quote validates product, live stock, B2B price,
            // minimum order quantity and ordering increment before commit.
            $this->quotes->quoteCart($cart, $user, null, null, true);
        }, 3);
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     */
    public function updateCartItem(array $context, User $user, int $itemId, float $quantity): void
    {
        DB::transaction(function () use ($context, $user, $itemId, $quantity): void {
            $cart = $this->cart($context);
            abort_unless($cart instanceof Cart, 404);

            $item = CartItem::query()
                ->whereKey($itemId)
                ->where('cart_id', $cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $item->quantity = $quantity;
            $item->save();

            $this->quotes->quoteCart($cart, $user, null, null, true);
        }, 3);
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     */
    public function removeCartItem(array $context, int $itemId): void
    {
        $cart = $this->cart($context);
        abort_unless($cart instanceof Cart, 404);

        CartItem::query()
            ->whereKey($itemId)
            ->where('cart_id', $cart->getKey())
            ->firstOrFail()
            ->delete();
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     * @return Collection<int,Order>
     */
    public function purchaseOrders(array $context): Collection
    {
        return Order::query()
            ->where('store_id', $context['wholesale_store_id'])
            ->where('b2b_customer_id', $context['customer']->getKey())
            ->where('channel', 'b2b')
            ->withCount('items')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     * @return Collection<int,array<string,mixed>>
     */
    public function supplierInvoices(array $context): Collection
    {
        return Invoice::query()
            ->where('store_id', $context['wholesale_store_id'])
            ->where('b2b_customer_id', $context['customer']->getKey())
            ->latest('issued_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (Invoice $invoice): array {
                $amounts = $this->ledger->invoiceAmounts($invoice);

                return [
                    'id' => (int) $invoice->getKey(),
                    'invoice_number' => (string) ($invoice->invoice_number ?? ''),
                    'status' => (string) $invoice->status,
                    'currency' => (string) $invoice->currency,
                    'total' => (float) $amounts['invoice_total'],
                    'paid_amount' => (float) $amounts['paid_amount'],
                    'outstanding_amount' => (float) $amounts['outstanding_amount'],
                    'order_id' => $invoice->order_id === null ? null : (int) $invoice->order_id,
                    'issued_at' => $invoice->issued_at,
                    'due_at' => $invoice->due_at,
                ];
            });
    }

    /**
     * Canonical purchase-order lifecycle plus authoritative receipt lineage.
     *
     * @param array{customer:B2bCustomer,wholesale_store_id:int,restaurant_store_id:int} $context
     * @return Collection<int,array<string,mixed>>
     */
    public function supplyStatuses(array $context): Collection
    {
        return DB::table('orders')
            ->leftJoin('retail_replenishments', 'retail_replenishments.source_order_id', '=', 'orders.id')
            ->where('orders.store_id', $context['wholesale_store_id'])
            ->where('orders.b2b_customer_id', $context['customer']->getKey())
            ->where('orders.channel', 'b2b')
            ->where(function ($query) use ($context): void {
                $query->whereNull('retail_replenishments.id')
                    ->orWhere('retail_replenishments.retail_store_id', $context['restaurant_store_id']);
            })
            ->orderByDesc('orders.id')
            ->limit(50)
            ->get([
                'orders.id',
                'orders.status',
                'orders.created_at',
                'orders.requested_delivery_date',
                'orders.grand_total',
                'orders.currency',
                'retail_replenishments.id as replenishment_id',
                'retail_replenishments.received_at',
            ])
            ->map(static fn (object $row): array => [
                'order_id' => (int) $row->id,
                'order_status' => (string) $row->status,
                'created_at' => $row->created_at,
                'requested_delivery_date' => $row->requested_delivery_date,
                'grand_total' => (float) ($row->grand_total ?? 0),
                'currency' => (string) ($row->currency ?? ''),
                'received_at' => $row->received_at,
                'received' => $row->replenishment_id !== null,
            ]);
    }

    /**
     * @param array{customer:B2bCustomer} $context
     * @return Collection<int,Address>
     */
    public function checkoutAddresses(array $context, User $user): Collection
    {
        return $this->addresses
            ->queryFor($user, $context['customer'], 'b2b')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array{customer:B2bCustomer,wholesale_store_id:int} $context
     * @return list<string>
     */
    public function paymentMethods(array $context): array
    {
        $methods = collect((array) config('checkout.payment_methods', ['cash_on_delivery']))
            ->map(static fn ($method): string => trim((string) $method))
            ->filter(static fn (string $method): bool => $method !== '')
            ->unique()
            ->values();

        $finance = $this->ledger->summary($context['customer'], $context['wholesale_store_id']);
        if ((float) ($finance['purchasing_power'] ?? 0) > 0 && ! $methods->contains('account_credit')) {
            $methods->push('account_credit');
        }

        return $methods->values()->all();
    }
}
