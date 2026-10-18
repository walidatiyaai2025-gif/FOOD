<?php

namespace App\Domain\Assistant\Analytics;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final class AssistantAnalyticsToolSet
{
    public function __construct(private readonly AssistantAnalyticsReadService $service) {}

    /** @return list<AssistantToolInterface> */
    public function all(): array
    {
        return array_map(
            fn (string $key): AssistantToolInterface => new AssistantAnalyticsTool($this->service, $key),
            AssistantAnalyticsTool::KEYS,
        );
    }
}
