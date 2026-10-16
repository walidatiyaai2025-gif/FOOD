<?php

namespace App\Enterprise\Commercial;

use App\Models\Order;
use App\Models\User;
use App\Services\OperationalTenantScope;

final class CommercialContextGuard
{
    public function __construct(
        private readonly OperationalTenantScope $tenantScope,
    ) {}

    public function assert(
        User $actor,
        int $storeId,
        string $channel,
        string $permission,
    ): void {
        $channel = strtolower(trim($channel));
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);

        $this->tenantScope->assertStore($actor, $storeId, $permission);

        abort_unless(
            $this->tenantScope->storeChannel($storeId) === $channel,
            404,
        );
    }

    public function order(
        User $actor,
        int $orderId,
        string $permission = 'orders.view',
    ): Order {
        $order = Order::query()
            ->accessibleTo($actor)
            ->whereKey($orderId)
            ->firstOrFail();

        $this->assert(
            $actor,
            (int) $order->store_id,
            strtolower((string) $order->channel),
            $permission,
        );

        return $order;
    }
}
