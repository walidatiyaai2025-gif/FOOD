<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\B2bPriceResolver;
use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\CommerceQuoteService;
use App\Services\CustomerDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GuestCartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $token = $this->guestToken($request, false);
        $storeId = $request->integer('store');

        if ($user instanceof User) {
            $resolvedStoreId = $storeId > 0
                ? $storeId
                : ($token === null ? null : (int) $this->guestCartForToken($token)->store_id);

            if ($resolvedStoreId === null) {
                throw ValidationException::withMessages([
                    'store' => ['A store is required when initializing an authenticated cart.'],
                ]);
            }

            [$customer, $channel] = $this->customerContext($user, $resolvedStoreId, $request);

            $cart = $token === null
                ? null
                : $this->mergeGuestCart($token, $customer, $channel, $storeId > 0 ? $storeId : null);

            if ($cart === null) {
                $this->activeStoreForChannel($resolvedStoreId, $channel);

                if ($request->attributes->get('app_preview_read_only') === true) {
                    $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
                    $cart = Cart::query()
                        ->where('store_id', $resolvedStoreId)
                        ->where($customerColumn, $customer->getKey())
                        ->where('channel', $channel)
                        ->first();

                    if (! $cart instanceof Cart) {
                        return response()->json(
                            $this->emptyPreviewCartPayload(
                                $customer,
                                $resolvedStoreId,
                                $channel,
                            ),
                        );
                    }
                } else {
                    $cart = $this->customerCart($customer, $resolvedStoreId, $channel);
                }
            }

            return response()->json($this->cartPayload($cart->fresh()));
        }

        if ($token === null) {
            $validated = $request->validate([
                'store' => ['required', 'integer', 'min:1'],
            ]);
            $storeId = (int) $validated['store'];
            $this->activeStoreForChannel($storeId, 'b2c');

            $token = Str::random(64);
            $cart = Cart::query()->create([
                'store_id' => $storeId,
                'customer_id' => null,
                'guest_token' => $token,
                'channel' => 'b2c',
            ]);
        } else {
            $cart = $this->guestCartForToken($token);
            $this->activeStoreForChannel((int) $cart->store_id, 'b2c');

            if ($storeId > 0 && (int) $cart->store_id !== $storeId) {
                abort(409, 'Guest cart belongs to another store.');
            }
        }

        return response()
            ->json($this->cartPayload($cart))
            ->header('X-Guest-Token', $token);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $storeId = (int) $validated['store_id'];
        $productId = (int) $validated['product_id'];
        $quantity = (float) $validated['quantity'];
        $user = $this->apiUser($request);
        $token = $this->guestToken($request, false);

        if ($user instanceof User) {
            [$customer, $channel] = $this->customerContext($user, $storeId, $request);
            $this->activeStoreForChannel($storeId, $channel);

            $cart = $token === null
                ? $this->customerCart($customer, $storeId, $channel)
                : $this->mergeGuestCart($token, $customer, $channel, $storeId);
        } else {
            $this->activeStoreForChannel($storeId, 'b2c');

            $token ??= Str::random(64);
            $cart = Cart::query()
                ->whereNull('customer_id')
                ->where('channel', 'b2c')
                ->where('guest_token', $token)
                ->first();

            if ($cart !== null && (int) $cart->store_id !== $storeId) {
                abort(409, 'Guest cart belongs to another store.');
            }

            if ($cart === null) {
                $cart = Cart::query()->create([
                    'store_id' => $storeId,
                    'customer_id' => null,
                    'guest_token' => $token,
                    'channel' => 'b2c',
                ]);
            }
        }

        $state = $this->productState($storeId, $productId, true);
        $pricing = null;
        if ($user instanceof User && $channel === 'b2b') {
            $pricing = app(B2bPriceResolver::class)->resolve($customer, $storeId, $productId);
            $this->assertB2bQuantity($quantity, $pricing);
            $state['price'] = $pricing['price'];
        }

        $item = CartItem::query()->firstOrNew([
            'cart_id' => $cart->id,
            'product_id' => $productId,
        ]);

        $newQuantity = ($item->exists ? (float) $item->quantity : 0.0) + $quantity;
        if ($user instanceof User && $channel === 'b2b') {
            abort_unless(is_array($pricing), 500, 'B2B pricing context is unavailable.');
            $this->assertB2bQuantity($newQuantity, $pricing);
        }
        $this->assertQuantityAvailable($state['available_quantity'], $newQuantity);

        $item->quantity = $newQuantity;
        $item->unit_price_snapshot = $state['price'];
        $item->save();

        $response = response()->json($this->cartPayload($cart->fresh()), 201);

        return $user instanceof User
            ? $response
            : $response->header('X-Guest-Token', (string) $token);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        [$cart, $cartItem] = $this->ownedItem($request, $item);
        $this->activeStoreForChannel((int) $cart->store_id, (string) $cart->channel);

        $state = $this->productState(
            (int) $cart->store_id,
            (int) $cartItem->product_id,
            true,
        );

        $quantity = (float) $validated['quantity'];
        if ((string) $cart->channel === 'b2b' && $cart->b2b_customer_id !== null) {
            $customer = B2bCustomer::query()->findOrFail((int) $cart->b2b_customer_id);
            $pricing = app(B2bPriceResolver::class)->resolve($customer, (int) $cart->store_id, (int) $cartItem->product_id);
            $this->assertB2bQuantity($quantity, $pricing);
            $state['price'] = $pricing['price'];
        }
        $this->assertQuantityAvailable($state['available_quantity'], $quantity);

        $cartItem->quantity = $quantity;
        $cartItem->unit_price_snapshot = $state['price'];
        $cartItem->save();

        return response()->json($this->cartPayload($cart->fresh()));
    }

    public function removeItem(Request $request, int $item): Response
    {
        [, $cartItem] = $this->ownedItem($request, $item);
        $cartItem->delete();

        return response()->noContent();
    }

    private function apiUser(Request $request): ?User
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User && $request->attributes->has('app_preview_session')) {
            $user = $request->user();
        }

        if ($user instanceof User) {
            abort_unless($user->is_active, 401, 'Unauthenticated.');

            return $user;
        }

        if ($request->bearerToken() !== null) {
            abort(401, 'Unauthenticated.');
        }

        return null;
    }

    /** @return array{0: B2bCustomer|B2cCustomer, 1: string} */
    private function customerContext(User $user, int $storeId, Request $request): array
    {
        return app(CustomerDomainResolver::class)->forStore($user, $storeId, $request);
    }

    private function guestToken(Request $request, bool $required): ?string
    {
        $token = trim((string) $request->header('X-Guest-Token', ''));

        if ($token === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    'X-Guest-Token' => ['A guest cart token is required.'],
                ]);
            }

            return null;
        }

        if (strlen($token) < 16 || strlen($token) > 200) {
            throw ValidationException::withMessages([
                'X-Guest-Token' => ['The guest cart token is invalid.'],
            ]);
        }

        return $token;
    }

    private function guestCartForToken(string $token): Cart
    {
        return Cart::query()
            ->whereNull('customer_id')
            ->where('channel', 'b2c')
            ->where('guest_token', $token)
            ->firstOrFail();
    }

    private function customerCart(B2bCustomer|B2cCustomer $customer, int $storeId, string $channel): Cart
    {
        $resolver = app(CustomerDomainResolver::class);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        return Cart::query()->firstOrCreate(
            [
                'store_id' => $storeId,
                $customerColumn => $customer->getKey(),
                'channel' => $channel,
            ],
            [
                'customer_id' => $resolver->legacyId($customer),
                'guest_token' => null,
            ],
        );
    }

    private function mergeGuestCart(
        string $token,
        B2bCustomer|B2cCustomer $customer,
        string $channel,
        ?int $requestedStoreId = null,
    ): Cart {
        abort_if($channel !== 'b2c', 409, 'A B2C guest cart cannot be merged into a B2B cart.');

        $guestCart = $this->guestCartForToken($token);
        $storeId = (int) $guestCart->store_id;

        if ($requestedStoreId !== null && $storeId !== $requestedStoreId) {
            abort(409, 'Guest cart belongs to another store.');
        }

        $this->activeStoreForChannel($storeId, 'b2c');
        $target = $this->customerCart($customer, $storeId, 'b2c');

        DB::transaction(function () use ($guestCart, $target, $storeId): void {
            $guestItems = CartItem::query()
                ->where('cart_id', $guestCart->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($guestItems as $guestItem) {
                $state = $this->productState($storeId, (int) $guestItem->product_id, true);

                $targetItem = CartItem::query()->firstOrNew([
                    'cart_id' => $target->getKey(),
                    'product_id' => $guestItem->product_id,
                ]);

                $quantity = ($targetItem->exists ? (float) $targetItem->quantity : 0.0)
                    + (float) $guestItem->quantity;

                $this->assertQuantityAvailable($state['available_quantity'], $quantity);

                $targetItem->quantity = $quantity;
                $targetItem->unit_price_snapshot = $state['price'];
                $targetItem->save();
            }

            $guestCart->delete();
        });

        return $target->fresh();
    }

    /** @return array{0: Cart, 1: CartItem} */
    private function ownedItem(Request $request, int $itemId): array
    {
        $user = $this->apiUser($request);

        if ($user instanceof User) {
            $cart = Cart::query()
                ->select('carts.*')
                ->join('cart_items', 'cart_items.cart_id', '=', 'carts.id')
                ->where('cart_items.id', $itemId)
                ->firstOrFail();

            [$customer, $channel] = $this->customerContext($user, (int) $cart->store_id, $request);
            $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

            $item = CartItem::query()
                ->select('cart_items.*')
                ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->where('cart_items.id', $itemId)
                ->where("carts.{$customerColumn}", $customer->getKey())
                ->where('carts.channel', $channel)
                ->firstOrFail();

            return [$cart, $item];
        }

        $token = $this->guestToken($request, true);
        $cart = $this->guestCartForToken((string) $token);

        $item = CartItem::query()
            ->whereKey($itemId)
            ->where('cart_id', $cart->getKey())
            ->firstOrFail();

        return [$cart, $item];
    }

    private function activeStoreForChannel(int $storeId, string $channel): Store
    {
        return Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereKey($storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', strtoupper($channel))
            ->firstOrFail();
    }

    /**
     * @return array{price: float|null, available_quantity: float|null, is_available: bool}
     */
    private function productState(int $storeId, int $productId, bool $mustBeAvailable): array
    {
        $product = Product::query()->forStore($storeId)->whereKey($productId)->first();

        $storeProduct = DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->first();

        $catalogAvailable = $product instanceof Product
            && (bool) $product->is_active
            && $storeProduct !== null
            && (bool) $storeProduct->is_active
            && $storeProduct->price !== null;

        if (! $catalogAvailable) {
            if ($mustBeAvailable) {
                abort(404, 'Product is not available in this store.');
            }

            return [
                'price' => null,
                'available_quantity' => 0.0,
                'is_available' => false,
            ];
        }

        $inventoryRows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->get(['inventories.quantity', 'inventories.reserved_quantity']);

        $availableQuantity = $inventoryRows->isEmpty()
            ? 0.0
            : (float) $inventoryRows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            );

        if ($mustBeAvailable && $availableQuantity <= 0) {
            abort(409, 'Product is out of stock.');
        }

        return [
            'price' => (float) $storeProduct->price,
            'available_quantity' => $availableQuantity,
            'is_available' => $availableQuantity > 0,
            'availability_state' => $availableQuantity > 0 ? 'AVAILABLE' : 'OUT_OF_STOCK',
        ];
    }

    /** @param array{minimum_quantity: float, ordering_increment: float} $pricing */
    private function assertB2bQuantity(float $quantity, array $pricing): void
    {
        abort_unless(
            $this->isValidB2bQuantity($quantity, $pricing),
            409,
            'Quantity must meet the B2B minimum and ordering increment.',
        );
    }

    /** @param array{minimum_quantity: float, ordering_increment: float} $pricing */
    private function isValidB2bQuantity(float $quantity, array $pricing): bool
    {
        $minimum = (float) $pricing['minimum_quantity'];
        $increment = max(0.001, (float) $pricing['ordering_increment']);
        if ($quantity + 0.0001 < $minimum) {
            return false;
        }

        $steps = ($quantity - $minimum) / $increment;

        return abs($steps - round($steps)) < 0.0001;
    }

    private function assertQuantityAvailable(?float $availableQuantity, float $requestedQuantity): void
    {
        if ($availableQuantity !== null && $requestedQuantity > $availableQuantity) {
            abort(409, 'Requested quantity exceeds available stock.');
        }
    }

    /**
     * Read-only preview must never materialize a cart as a side effect of GET.
     *
     * @return array<string,mixed>
     */
    private function emptyPreviewCartPayload(
        B2bCustomer|B2cCustomer $customer,
        int $storeId,
        string $channel,
    ): array {
        return [
            'id' => null,
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id === null
                ? null
                : (int) $customer->legacy_customer_id,
            'b2b_customer_id' => $channel === 'b2b' ? (int) $customer->getKey() : null,
            'b2c_customer_id' => $channel === 'b2c' ? (int) $customer->getKey() : null,
            'channel' => $channel,
            'guest_token' => null,
            'currency' => 'KWD',
            'items' => [],
            'subtotal' => 0.0,
            'has_unavailable_items' => false,
            'quote' => [
                'quote_id' => null,
                'quoted_at' => now()->toIso8601String(),
                'promotion_discount_total' => 0.0,
                'discount_total' => 0.0,
                'delivery_total' => 0.0,
                'tax_rate' => 0.0,
                'tax_total' => 0.0,
                'grand_total' => 0.0,
                'pricing_source' => 'preview_read_only',
            ],
        ];
    }

    private function cartPayload(Cart $cart): array
    {
        // Cart review uses the same live quote engine as Checkout and Dashboard ordering.
        $quote = app(CommerceQuoteService::class)->quoteCart(
            $cart,
            null,
            null,
            null,
            false,
        );

        $currency = (string) $quote['currency'];
        $items = collect($quote['items'])->map(static function (array $line) use ($currency): array {
            return [
                'id' => isset($line['cart_item_id']) ? (int) $line['cart_item_id'] : null,
                'product' => [
                    'id' => (int) $line['product_id'],
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'category_id' => $line['category_id'],
                    'brand_id' => $line['brand_id'],
                    'is_active' => $line['sku'] !== null,
                    'price' => $line['base_unit_price'],
                    'currency' => $currency,
                ],
                'quantity' => (float) $line['quantity'],
                'unit_price_snapshot' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'is_available' => (bool) $line['is_available'],
                'available_quantity' => $line['available_quantity'],
                'availability_state' => $line['availability_state'],
                'minimum_order_quantity' => $line['minimum_order_quantity'],
                'ordering_increment' => $line['ordering_increment'],
                'pack_size' => $line['pack_size'],
                'case_size' => $line['case_size'],
                'pack_label' => $line['pack_label'],
                'price_tier_id' => $line['price_tier_id'],
                'price_tier_code' => $line['price_tier_code'],
            ];
        })->values()->all();

        return [
            'id' => (int) $cart->id,
            'store_id' => (int) $cart->store_id,
            'customer_id' => $cart->customer_id === null ? null : (int) $cart->customer_id,
            'b2b_customer_id' => $cart->b2b_customer_id === null ? null : (int) $cart->b2b_customer_id,
            'b2c_customer_id' => $cart->b2c_customer_id === null ? null : (int) $cart->b2c_customer_id,
            'channel' => (string) $cart->channel,
            'guest_token' => $cart->guest_token,
            'currency' => (string) $quote['currency'],
            'items' => $items,
            'subtotal' => (float) $quote['subtotal'],
            'has_unavailable_items' => (bool) $quote['has_unavailable_items'],
            'quote' => [
                'quote_id' => $quote['quote_id'],
                'quoted_at' => $quote['quoted_at'],
                'promotion_discount_total' => (float) $quote['promotion_discount_total'],
                'discount_total' => (float) $quote['discount_total'],
                'delivery_total' => (float) $quote['delivery_total'],
                'tax_rate' => (float) $quote['tax_rate'],
                'tax_total' => (float) $quote['tax_total'],
                'grand_total' => (float) $quote['grand_total'],
                'pricing_source' => $quote['pricing_source'],
            ],
        ];
    }
}
