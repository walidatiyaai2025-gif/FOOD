<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

final class FulfillmentActorPolicy
{
    public const VAN = 'van';

    public const DRIVER = 'driver';

    public function actorForChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'b2b' => self::VAN,
            'b2c' => self::DRIVER,
            default => throw ValidationException::withMessages([
                'channel' => ['Unsupported order channel for fulfillment.'],
            ]),
        };
    }

    public function assertOrderActor(Order $order, string $actor): void
    {
        $expected = $this->actorForChannel((string) $order->channel);
        $actual = strtolower(trim($actor));

        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'assignee_type' => [
                    sprintf(
                        'Order channel %s must be fulfilled by %s, not %s.',
                        strtolower((string) $order->channel),
                        $expected,
                        $actual === '' ? 'unknown' : $actual,
                    ),
                ],
            ]);
        }
    }
}
