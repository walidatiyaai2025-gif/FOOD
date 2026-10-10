<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\User;
use Throwable;

final class B2BOrderRoutingCoordinator
{
    public function __construct(
        private readonly OrderTerritoryRoutingService $routing,
    ) {}

    public function routeCreatedOrder(
        Order $order,
        ?User $actor,
        string $source,
    ): ?OrderDispatchState {
        $order->refresh();

        if (strtolower(trim((string) $order->channel)) !== 'b2b') {
            return null;
        }

        $source = strtolower(trim($source));
        if ($source === '') {
            $source = 'unknown';
        }

        $current = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->first();

        if (
            $current instanceof OrderDispatchState
            && (string) $current->status === 'assigned'
            && (string) $current->current_assignee_type === 'van'
            && $current->current_assignee_id !== null
        ) {
            return $current;
        }

        try {
            return $this->routing->route($order, $actor);
        } catch (Throwable $exception) {
            report($exception);

            try {
                return $this->routing->deferToDispatch(
                    $order,
                    $actor,
                    'post_create_guard',
                    'routing_exception',
                    [
                        'post_create_source' => $source,
                        'exception_class' => $exception::class,
                    ],
                );
            } catch (Throwable $deferException) {
                report($deferException);

                return null;
            }
        }
    }
}
