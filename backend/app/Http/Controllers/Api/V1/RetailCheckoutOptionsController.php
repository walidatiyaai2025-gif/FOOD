<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Services\CustomerAddressService;
use App\Services\CustomerDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetailCheckoutOptionsController extends Controller
{
    public function __invoke(
        Request $request,
        CustomerDomainResolver $customers,
        CustomerAddressService $addresses,
    ): JsonResponse {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = (int) $validated['store_id'];
        [$customer, $channel] = $customers->forStore($user, $storeId, $request);
        abort_unless($channel === 'b2c' && $customer instanceof B2cCustomer, 403);

        $addressRows = $addresses
            ->queryFor($user, $customer, $channel)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(static fn (Address $address): array => [
                'id' => (int) $address->getKey(),
                'label' => $address->label,
                'recipient_name' => $address->recipient_name,
                'delivery_phone' => $address->delivery_phone,
                'line1' => (string) $address->line1,
                'line2' => $address->line2,
                'city' => (string) $address->city,
                'area' => $address->area,
                'country_code' => (string) $address->country_code,
                'is_default' => (bool) $address->is_default,
            ])
            ->values()
            ->all();

        $paymentMethods = collect((array) config('checkout.payment_methods', ['cash_on_delivery']))
            ->map(static fn ($method): string => trim((string) $method))
            ->filter(static fn (string $method): bool => $method !== '')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'store_id' => $storeId,
            'addresses' => $addressRows,
            'payment_methods' => $paymentMethods,
        ]);
    }
}
