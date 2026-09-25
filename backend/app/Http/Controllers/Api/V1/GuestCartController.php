<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\B2bPriceResolver;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
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
            [$customer, $channel] = $this->customerContext($user);

            $cart = $token === null
                ? null
                : $this->mergeGuestCart($token, $customer, $channel, $storeId > 0 ? $storeId : null);

            if ($cart === null) {
                if ($storeId <= 0) {
                    throw ValidationException::withMessages([
                        'store' => ['A store is required when initializing an authenticated cart.'],
                    ]);
                }

                $this->activeStoreForChannel($storeId, $channel);
                $cart = $this->customerCart($customer, $storeId, $channel);
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
            [$customer, $channel] = $this->customerContext($user);
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
        if ($user instanceof User && $channel === 'b2b') {
            $pricing = app(B2bPriceResolver::class)->resolve($customer, $storeId, $productId);
            abort_if($quantity < $pricing['minimum_quantity'], 409, 'Quantity is below the B2B minimum purchase quantity.');
            $state['price'] = $pricing['price'];
        }

        $item = CartItem::query()->firstOrNew([
            'cart_id' => $cart->id,
            'product_id' => $productId,
        ]);

        $newQuantity = ($item->exists ? (float) $item->quantity : 0.0) + $quantity;
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

        if ($user instanceof User) {
            abort_unless($user->is_active, 401, 'Unauthenticated.');

            return $user;
        }

        if ($request->bearerToken() !== null) {
            abort(401, 'Unauthenticated.');
        }

        return null;
    }

    /** @return array{0: Customer, 1: string} */
    private function customerContext(User $user): array
    {
        $customer = Customer::query()
            ->where('user_id', $user->getKey())
            ->first();

        abort_unless($customer instanceof Customer, 403, 'Customer profile is required.');

        $channel = strtolower((string) $customer->type);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403, 'Unsupported customer channel.');

        return [$customer, $channel];
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

    private function customerCart(Customer $customer, int $storeId, string $channel): Cart
    {
        return Cart::query()->firstOrCreate(
            [
                'store_id' => $storeId,
                'customer_id' => $customer->getKey(),
                'channel' => $channel,
            ],
            ['guest_token' => null],
        );
    }

    private function mergeGuestCart(
        string $token,
        Customer $customer,
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
            [$customer, $channel] = $this->customerContext($user);

            $item = CartItem::query()
                ->select('cart_items.*')
                ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->where('cart_items.id', $itemId)
                ->where('carts.customer_id', $customer->getKey())
                ->where('carts.channel', $channel)
                ->firstOrFail();

            $cart = Cart::query()->findOrFail($item->cart_id);

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
        $product = Product::query()->whereKey($productId)->first();

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
            ? null
            : (float) $inventoryRows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            );

        if ($mustBeAvailable && $availableQuantity !== null && $availableQuantity <= 0) {
            abort(409, 'Product is out of stock.');
        }

        return [
            'price' => (float) $storeProduct->price,
            'available_quantity' => $availableQuantity,
            'is_available' => $availableQuantity === null || $availableQuantity > 0,
        ];
    }

    private function assertQuantityAvailable(?float $availableQuantity, float $requestedQuantity): void
    {
        if ($availableQuantity !== null && $requestedQuantity > $availableQuantity) {
            abort(409, 'Requested quantity exceeds available stock.');
        }
    }

    private function cartPayload(Cart $cart): array
    {
        $rows = DB::table('cart_items')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('cart_items.cart_id', $cart->id)
            ->orderBy('cart_items.id')
            ->get([
                'cart_items.id',
                'cart_items.product_id',
                'cart_items.quantity',
                'cart_items.unit_price_snapshot',
                'products.sku',
                'products.name',
                'products.category_id',
                'products.brand_id',
                'products.is_active',
            ]);

        $subtotal = 0.0;
        $hasUnavailableItems = false;

        $items = $rows->map(function (object $row) use ($cart, &$subtotal, &$hasUnavailableItems): array {
            $quantity = (float) $row->quantity;
            $state = $this->productState((int) $cart->store_id, (int) $row->product_id, false);
            $quantityAvailable = $state['available_quantity'] === null
                || $quantity <= $state['available_quantity'];
            $isAvailable = $state['is_available'] && $quantityAvailable;
            $unitPrice = $isAvailable ? $state['price'] : null;

            if ($isAvailable && (string) $cart->channel === 'b2b' && $cart->customer_id !== null) {
                $customer = Customer::query()->find($cart->customer_id);
                if ($customer instanceof Customer) {
                    $pricing = app(B2bPriceResolver::class)->resolve($customer, (int) $cart->store_id, (int) $row->product_id);
                    $isAvailable = $quantity >= $pricing['minimum_quantity'];
                    $unitPrice = $isAvailable ? $pricing['price'] : null;
                }
            }

            $lineTotal = $unitPrice === null ? null : round($quantity * $unitPrice, 3);

            if ($isAvailable && $unitPrice !== null) {
                if ((float) $row->unit_price_snapshot !== $unitPrice) {
                    DB::table('cart_items')
                        ->where('id', $row->id)
                        ->update([
                            'unit_price_snapshot' => $unitPrice,
                            'updated_at' => now(),
                        ]);
                }

                $subtotal += (float) $lineTotal;
            } else {
                $hasUnavailableItems = true;
            }

            return [
                'id' => (int) $row->id,
                'product' => [
                    'id' => (int) $row->product_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'is_active' => (bool) $row->is_active,
                    'price' => $state['price'],
                    'currency' => 'KWD',
                ],
                'quantity' => $quantity,
                'unit_price_snapshot' => $unitPrice,
                'line_total' => $lineTotal,
                'is_available' => $isAvailable,
                'available_quantity' => $state['available_quantity'],
            ];
        })->values()->all();

        return [
            'id' => (int) $cart->id,
            'store_id' => (int) $cart->store_id,
            'customer_id' => $cart->customer_id === null ? null : (int) $cart->customer_id,
            'channel' => (string) $cart->channel,
            'guest_token' => $cart->guest_token,
            'currency' => 'KWD',
            'items' => $items,
            'subtotal' => round($subtotal, 3),
            'has_unavailable_items' => $hasUnavailableItems,
        ];
    }
}
