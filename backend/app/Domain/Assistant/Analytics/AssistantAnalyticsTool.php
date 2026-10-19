<?php

namespace App\Domain\Assistant\Analytics;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use InvalidArgumentException;

final class AssistantAnalyticsTool implements AssistantToolInterface
{
    public const KEYS = [
        'analytics.summary',
        'analytics.diagnose',
        'inventory.snapshot',
        'refunds.summary',
        'retailers.performance',
        'retailers.360',
        'wholesale.purchases',
        'forecast.replenishment',
        'margin.summary',
        'support.summary',
    ];

    public function __construct(
        private readonly AssistantAnalyticsReadService $service,
        private readonly string $toolKey,
    ) {
        if (! in_array($toolKey, self::KEYS, true)) {
            throw new InvalidArgumentException("Unsupported Assistant analytics tool [{$toolKey}].");
        }
    }

    public function key(): string
    {
        return $this->toolKey;
    }

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        return $this->service->execute($this->toolKey, $request);
    }
}
