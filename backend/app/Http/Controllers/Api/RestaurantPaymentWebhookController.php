<?php

namespace App\Http\Controllers\Api;

use App\Domain\SmartOrdering\RestaurantPaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RestaurantPaymentWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        RestaurantPaymentService $payments,
    ): JsonResponse {
        $payment = $payments->reconcileWebhook($provider, $request);

        return response()->json([
            'received' => true,
            'payment' => [
                'provider' => (string) $payment->provider,
                'status' => (string) $payment->status,
            ],
        ]);
    }
}
