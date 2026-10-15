<?php

namespace App\Http\Controllers\Api;

use App\Domain\SmartOrdering\CartService;
use App\Domain\SmartOrdering\CheckoutService;
use App\Domain\SmartOrdering\LifecycleService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingController extends Controller
{
    public function createCart(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'branch_public_id' => ['required', 'uuid'],
            'revision_public_id' => ['nullable', 'uuid'],
            'attribution_context' => ['nullable', 'string', 'max:512'],
        ]);

        $result = $carts->create(
            (string) $data['branch_public_id'],
            isset($data['revision_public_id']) ? (string) $data['revision_public_id'] : null,
            isset($data['attribution_context']) ? (string) $data['attribution_context'] : null,
        );

        return response()->json([
            'cart' => [
                'public_id' => (string) $result['cart']->public_id,
                'token' => $result['token'],
                'expires_at' => $result['cart']->expires_at === null
                    ? null
                    : CarbonImmutable::parse((string) $result['cart']->expires_at)->toAtomString(),
            ],
            'quote' => $result['quote'],
        ], 201);
    }

    public function showCart(string $cart, Request $request, CartService $carts): JsonResponse
    {
        $model = $carts->authorized($cart, $this->cartToken($request));

        return response()->json(['data' => $carts->show($model)]);
    }

    public function addItem(string $cart, Request $request, CartService $carts): JsonResponse
    {
        $model = $carts->authorized($cart, $this->cartToken($request));
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999'],
            'selection' => ['nullable', 'array'],
            'selection.variant_product_id' => ['nullable', 'integer', 'min:1'],
            'selection.modifiers' => ['nullable', 'array', 'max:20'],
            'selection.modifiers.*.product_id' => ['required_with:selection.modifiers', 'integer', 'min:1'],
            'selection.modifiers.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:50'],
            'selection.extras' => ['nullable', 'array', 'max:20'],
            'selection.extras.*.product_id' => ['required_with:selection.extras', 'integer', 'min:1'],
            'selection.extras.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $carts->add($model, $data)], 201);
    }

    public function updateItem(
        string $cart,
        string $item,
        Request $request,
        CartService $carts,
    ): JsonResponse {
        $model = $carts->authorized($cart, $this->cartToken($request));
        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:999'],
            'selection' => ['sometimes', 'array'],
            'selection.variant_product_id' => ['nullable', 'integer', 'min:1'],
            'selection.modifiers' => ['nullable', 'array', 'max:20'],
            'selection.modifiers.*.product_id' => ['required_with:selection.modifiers', 'integer', 'min:1'],
            'selection.modifiers.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:50'],
            'selection.extras' => ['nullable', 'array', 'max:20'],
            'selection.extras.*.product_id' => ['required_with:selection.extras', 'integer', 'min:1'],
            'selection.extras.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $carts->update($model, $item, $data)]);
    }

    public function removeItem(
        string $cart,
        string $item,
        Request $request,
        CartService $carts,
    ): JsonResponse {
        $model = $carts->authorized($cart, $this->cartToken($request));

        return response()->json(['quote' => $carts->remove($model, $item)]);
    }

    public function checkout(
        string $cart,
        Request $request,
        CartService $carts,
        CheckoutService $checkout,
    ): JsonResponse {
        $model = $carts->authorized($cart, $this->cartToken($request), true);
        $data = $request->validate([
            'fulfillment_mode' => ['required', Rule::in(['dine_in', 'pickup', 'delivery'])],
            'scheduled_for' => ['nullable', 'date'],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
            'table_public_id' => ['nullable', 'uuid'],
            'table_token' => ['nullable', 'string', 'max:255'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'order_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $checkout->checkout(
            $model,
            (string) $request->header('Idempotency-Key', ''),
            $data,
        );

        return response()->json([
            'data' => $this->orderPayload($result['order'], $result['context']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function tracking(string $reference, LifecycleService $lifecycle): JsonResponse
    {
        return response()->json(['data' => $lifecycle->tracking($reference)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function cartToken(Request $request): string
    {
        $token = trim((string) $request->header('X-Smart-Cart-Token', ''));
        abort_if($token === '', 401, 'Cart token is required.');

        return $token;
    }

    /** @return array<string,mixed> */
    private function orderPayload(object $order, object $context): array
    {
        return [
            'reference' => (string) $context->public_reference,
            'order_number' => (string) $order->order_number,
            'status' => (string) $context->lifecycle_status,
            'fulfillment_mode' => (string) $context->fulfillment_mode,
            'scheduled_for' => $context->scheduled_for?->toAtomString(),
            'currency' => (string) $order->currency,
            'subtotal' => (float) $context->subtotal,
            'discount_total' => (float) $context->discount_total,
            'delivery_total' => (float) $context->delivery_total,
            'service_charge_total' => (float) $context->service_charge_total,
            'tax_total' => (float) $context->tax_total,
            'grand_total' => (float) $context->grand_total,
        ];
    }
}
