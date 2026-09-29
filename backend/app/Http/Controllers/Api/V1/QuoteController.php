<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Services\CommerceQuoteService;
use App\Services\CustomerDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuoteController extends Controller
{
    public function __invoke(Request $request, CommerceQuoteService $quotes): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = (int) $validated['store_id'];
        [$customer, $channel] = app(CustomerDomainResolver::class)->forStore($user, $storeId, $request);
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';

        $cart = Cart::query()
            ->where('store_id', $storeId)
            ->where($customerColumn, $customer->getKey())
            ->where('channel', $channel)
            ->first();

        abort_unless($cart instanceof Cart, 409, 'No authenticated cart exists for this store.');

        $quote = $quotes->quoteCart(
            $cart,
            $user,
            isset($validated['coupon_code']) ? (string) $validated['coupon_code'] : null,
            isset($validated['payment_method']) ? (string) $validated['payment_method'] : null,
            true,
        );

        return response()->json(['data' => $quote]);
    }
}
