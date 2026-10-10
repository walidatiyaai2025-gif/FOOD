<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OrderCreatedRoutingService
{
    public function __construct(
        private readonly OrderTerritoryRoutingService $routing,
    ) {}

    public function route(
        Order $order,
        ?User $actor = null,
        string $source = 'unknown',
    ): ?OrderDispatchState {
        $order->refresh();

        if (strtolower((string) $order->channel) !== 'b2b') {
            return null;
        }

        $source = strtolower(trim($source));
        if ($source === '') {
            $source = 'unknown';
        }

        try {
            return $this->routing->route($order, $actor, null, $source);
        } catch (Throwable $exception) {
            try {
                return $this->routing->markPostCreateFailure(
                    $order,
                    $actor,
                    $source,
                    'routing_exception',
                    [
                        'exception_class' => $exception::class,
                    ],
                );
            } catch (Throwable $fallbackException) {
                Log::error('B2B order post-create routing failed without dispatch persistence.', [
                    'order_id' => (int) $order->getKey(),
                    'order_source' => $source,
                    'routing_exception' => $exception::class,
                    'fallback_exception' => $fallbackException::class,
                ]);

                return null;
            }
        }
    }
}
