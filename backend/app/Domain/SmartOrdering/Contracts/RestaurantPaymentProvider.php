<?php

namespace App\Domain\SmartOrdering\Contracts;

use App\Domain\SmartOrdering\RestaurantPaymentProviderResult;
use App\Domain\SmartOrdering\RestaurantPaymentWebhookResult;
use App\Models\Payment;
use Illuminate\Http\Request;

interface RestaurantPaymentProvider
{
    public function supports(string $operation): bool;

    /** @param array<string,mixed> $context */
    public function execute(
        string $operation,
        Payment $payment,
        string $amount,
        string $currency,
        array $context = [],
    ): RestaurantPaymentProviderResult;

    public function verifyWebhook(Request $request): bool;

    public function webhook(Request $request): RestaurantPaymentWebhookResult;
}
