<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RestaurantWholesalePurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class RestaurantWholesalePurchasesController extends Controller
{
    private const TABS = ['catalog', 'cart', 'orders', 'invoices', 'delivery'];

    public function index(
        Request $request,
        RestaurantWholesalePurchaseService $purchases,
        B2bPricingController $pricing,
    ): View {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $context = $purchases->context($request, $user);
        $tab = strtolower((string) $request->query('tab', 'catalog'));
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'catalog';
        }

        $catalog = [];
        $cart = null;
        $cartItems = [];
        $orders = collect();
        $invoices = collect();
        $supplyStatuses = collect();
        $addresses = collect();
        $paymentMethods = [];

        if ($tab === 'catalog') {
            // Reuse the canonical B2B catalog/pricing authority. This controller
            // never obtains B2B admin permissions; it supplies only buyer context.
            $request->merge([
                'retail_store_id' => $context['restaurant_store_id'],
                'store_id' => $context['wholesale_store_id'],
            ]);
            $response = $pricing->products($request);
            $payload = $response->getData(true);
            $catalog = is_array($payload) ? (array) ($payload['data'] ?? []) : [];
        }

        if ($tab === 'cart') {
            $cart = $purchases->cartSnapshot($context, $user);
            $cartItems = is_array($cart) ? (array) ($cart['items'] ?? []) : [];
            $addresses = $purchases->checkoutAddresses($context, $user);
            $paymentMethods = $purchases->paymentMethods($context);
        }

        if ($tab === 'orders') {
            $orders = $purchases->purchaseOrders($context);
        }

        if ($tab === 'invoices') {
            $invoices = $purchases->supplierInvoices($context);
        }

        if ($tab === 'delivery') {
            $supplyStatuses = $purchases->supplyStatuses($context);
        }

        return view('admin.restaurant-wholesale-purchases', [
            'context' => $context,
            'tab' => $tab,
            'tabs' => self::TABS,
            'catalog' => $catalog,
            'cart' => $cart,
            'cartItems' => $cartItems,
            'orders' => $orders,
            'invoices' => $invoices,
            'supplyStatuses' => $supplyStatuses,
            'addresses' => $addresses,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function addCartItem(
        Request $request,
        RestaurantWholesalePurchaseService $purchases,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $context = $purchases->context($request, $user);
        $purchases->addCartItem(
            $context,
            $user,
            (int) $validated['product_id'],
            (float) $validated['quantity'],
        );

        return $this->backTo($context['restaurant_store_id'], 'cart')
            ->with('status', __('restaurant_wholesale.messages.cart_added'));
    }

    public function updateCartItem(
        Request $request,
        int $item,
        RestaurantWholesalePurchaseService $purchases,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $context = $purchases->context($request, $user);
        $purchases->updateCartItem($context, $user, $item, (float) $validated['quantity']);

        return $this->backTo($context['restaurant_store_id'], 'cart')
            ->with('status', __('restaurant_wholesale.messages.cart_updated'));
    }

    public function removeCartItem(
        Request $request,
        int $item,
        RestaurantWholesalePurchaseService $purchases,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
        ]);

        $context = $purchases->context($request, $user);
        $purchases->removeCartItem($context, $item);

        return $this->backTo($context['restaurant_store_id'], 'cart')
            ->with('status', __('restaurant_wholesale.messages.cart_removed'));
    }

    public function checkout(
        Request $request,
        RestaurantWholesalePurchaseService $purchases,
        CheckoutController $checkout,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', Rule::in([
                ...collect((array) config('checkout.payment_methods', ['cash_on_delivery']))
                    ->map(static fn ($method): string => trim((string) $method))
                    ->filter()
                    ->values()
                    ->all(),
                'account_credit',
            ])],
            'requested_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);

        $context = $purchases->context($request, $user);
        abort_unless(
            in_array((string) $validated['payment_method'], $purchases->paymentMethods($context), true),
            422,
            'Payment method is not available for this Wholesale buyer account.',
        );

        // Hand off order creation to the existing canonical checkout. Restaurant
        // procurement owns only the surface and buyer context, never a parallel order engine.
        $request->merge([
            'store_id' => $context['wholesale_store_id'],
            'retail_store_id' => $context['restaurant_store_id'],
            'address_id' => (int) $validated['address_id'],
            'payment_method' => (string) $validated['payment_method'],
            'requested_delivery_date' => $validated['requested_delivery_date'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);
        $request->headers->set('Idempotency-Key', (string) $validated['idempotency_key']);

        /** @var JsonResponse $response */
        $response = app()->call([$checkout, '__invoke'], ['request' => $request]);
        $payload = $response->getData(true);
        $orderId = is_array($payload) ? (int) ($payload['id'] ?? 0) : 0;

        return $this->backTo($context['restaurant_store_id'], 'orders')
            ->with('status', __('restaurant_wholesale.messages.order_submitted', [
                'id' => $orderId > 0 ? $orderId : '—',
            ]));
    }

    private function backTo(int $restaurantStoreId, string $tab): RedirectResponse
    {
        return redirect()->route('admin.restaurant.wholesale-purchases.index', [
            'retail_store_id' => $restaurantStoreId,
            'tab' => $tab,
        ]);
    }
}
