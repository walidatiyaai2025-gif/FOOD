<?php

namespace App\Domain\SmartOrdering\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SmartOrderingOrderCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly string $publicReference,
        public readonly int $branchId,
        public readonly string $fulfillmentMode,
    ) {}
}
