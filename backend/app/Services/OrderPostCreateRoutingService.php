<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\User;
use Throwable;

final class OrderPostCreateRoutingService
{
    public const CUSTOMER_CHECKOUT = 'customer_checkout';

    public const DASHBOARD = 'dashboard';

    public const VAN = 'van';

    public const INTEGRATION = 'integration';

    public function __construct(
        private readonly OrderTerritoryRoutingService $routing,
        private readonly FulfillmentActorPolicy $actors,
    ) {}

    public function handle(
        Order $order,
        ?User $actor = null,
        string $source = self::INTEGRATION,
    ): ?OrderDispatchState {
        $order->refresh();

        if ($this->actors->actorForChannel((string) $order->channel) !== FulfillmentActorPolicy::VAN) {
            return null;
        }

        $source = strtolower(trim($source));
        if ($source === '') {
            $source = self::INTEGRATION;
        }

        try {
            $state = $this->routing->route($order, $actor);
        } catch (Throwable $exception) {
            report($exception);

            try {
                $state = $this->routing->awaitingDispatch(
                    $order,
                    $actor,
                    'routing_exception',
                    [
                        'order_source' => $source,
                        'exception_class' => $exception::class,
                    ],
                );
            } catch (Throwable $persistenceException) {
                report($persistenceException);

                return null;
            }
        }

        $context = is_array($state->context) ? $state->context : [];
        if (($context['order_source'] ?? null) !== $source) {
            $context['order_source'] = $source;
            $state->forceFill(['context' => $context])->save();
        }

        return $state->fresh();
    }
}
