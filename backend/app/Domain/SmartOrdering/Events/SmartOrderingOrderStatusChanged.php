<?php

namespace App\Domain\SmartOrdering\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SmartOrderingOrderStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $orderContextId,
        public readonly string $publicReference,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $fulfillmentMode,
        public readonly ?string $eventKey = null,
    ) {}
}
