<?php

namespace App\Domain\SmartOrdering\Infrastructure;

use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Domain\SmartOrdering\Events\SmartOrderingOrderCreated;
use App\Models\Order;
use App\Models\SmartOrderingOrderContext;

final class LaravelEventOrderNotificationBridge implements OrderNotificationBridge
{
    public function notify(Order $order, SmartOrderingOrderContext $context): void
    {
        event(new SmartOrderingOrderCreated(
            orderId: (int) $order->getKey(),
            publicReference: (string) $context->public_reference,
            branchId: (int) $context->branch_id,
            fulfillmentMode: (string) $context->fulfillment_mode,
        ));
    }
}
