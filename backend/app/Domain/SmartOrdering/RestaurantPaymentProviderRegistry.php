<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Contracts\RestaurantPaymentProvider;
use InvalidArgumentException;

final class RestaurantPaymentProviderRegistry
{
    public function configured(string $provider): bool
    {
        $class = config('restaurant_payments.providers.'.trim($provider));

        return is_string($class)
            && $class !== ''
            && is_a($class, RestaurantPaymentProvider::class, true);
    }

    public function provider(string $provider): RestaurantPaymentProvider
    {
        $provider = trim($provider);
        $class = config('restaurant_payments.providers.'.$provider);

        if (! is_string($class) || $class === '' || ! is_a($class, RestaurantPaymentProvider::class, true)) {
            throw new InvalidArgumentException("Restaurant payment provider [{$provider}] is not configured.");
        }

        $resolved = app($class);
        if (! $resolved instanceof RestaurantPaymentProvider) {
            throw new InvalidArgumentException("Restaurant payment provider [{$provider}] is invalid.");
        }

        return $resolved;
    }
}
