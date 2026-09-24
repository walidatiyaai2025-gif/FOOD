<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GuestCartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $token = $this->guestToken($request, false);
        $storeId = $request->integer('store');

        if ($token === null) {
            $validated = $request->validate([
                'store' => ['required', 'integer', 'min:1'],
            ]);
            $storeId = (int) $validated['store'];
            $this->activeB2cStore($storeId);

            $token = Str::random(64);
            $cart = Cart::query()->create([
                'store_id' => $storeId,
                'customer_id' => null,
                'guest_token' => $token,
                'channel' => 'b2c',
            ]);
        } else {
            $cart = $this->cartForToken($token);

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

        $this->activeB2cStore($storeId);
        $price = $this->availableProductPrice($storeId, $productId);

        $token = $this->guestToken($request, false) ?? Str::random(64);
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

        $item = CartItem::query()->firstOrNew([
            'cart_id' => $cart->id,
            'product_id' => $productId,
        ]);

        $item->quantity = $item->exists
            ? (float) $item->quantity + $quantity
            : $quantity;
        $item->unit_price_snapshot = $price;
        $item->save();

        return response()
            ->json($this->cartPayload($cart->fresh()), 201)
            ->header('X-Guest-Token', $token);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $token = $this->guestToken($request, true);
        $cart = $this->cartForToken($token);

        $cartItem = CartItem::query()
            ->whereKey($item)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $cartItem->quantity = (float) $validated['quantity'];
        $cartItem->unit_price_snapshot = $this->availableProductPrice(
            (int) $cart->store_id,
            (int) $cartItem->product_id,
        );
        $cartItem->save();

        return response()->json($this->cartPayload($cart->fresh()));
    }

    public function removeItem(Request $request, int $item): Response
    {
        $token = $this->guestToken($request, true);
        $cart = $this->cartForToken($token);

        $cartItem = CartItem::query()
            ->whereKey($item)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $cartItem->delete();

        return response()->noContent();
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

    private function cartForToken(string $token): Cart
    {
        return Cart::query()
            ->whereNull('customer_id')
            ->where('channel', 'b2c')
            ->where('guest_token', $token)
            ->firstOrFail();
    }

    private function activeB2cStore(int $storeId): Store
    {
        return Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereKey($storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->firstOrFail();
    }

    private function availableProductPrice(int $storeId, int $productId): float
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('is_active', true)
            ->firstOrFail();

        $storeProduct = DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->whereNotNull('price')
            ->first();

        abort_if($storeProduct === null, 404);

        return (float) $storeProduct->price;
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
        $items = $rows->map(function (object $row) use (&$subtotal): array {
            $quantity = (float) $row->quantity;
            $unitPrice = $row->unit_price_snapshot === null
                ? null
                : (float) $row->unit_price_snapshot;
            $lineTotal = $unitPrice === null ? null : round($quantity * $unitPrice, 3);

            if ($lineTotal !== null) {
                $subtotal += $lineTotal;
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
                    'price' => $unitPrice,
                    'currency' => 'KWD',
                ],
                'quantity' => $quantity,
                'unit_price_snapshot' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        })->values()->all();

        return [
            'id' => (int) $cart->id,
            'store_id' => (int) $cart->store_id,
            'channel' => 'b2c',
            'guest_token' => $cart->guest_token,
            'currency' => 'KWD',
            'items' => $items,
            'subtotal' => round($subtotal, 3),
        ];
    }
}
