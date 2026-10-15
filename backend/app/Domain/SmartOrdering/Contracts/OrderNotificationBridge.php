<?php

namespace App\Domain\SmartOrdering\Contracts;

use App\Models\Order;
use App\Models\SmartOrderingOrderContext;

interface OrderNotificationBridge
{
    public function notify(Order $order, SmartOrderingOrderContext $context): void;
}
