<?php

namespace App\Domain\Assistant\Executive;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final readonly class WholesaleExecutiveToolSet
{
    public function __construct(private WholesaleExecutiveIntelligenceService $service) {}

    /** @return list<AssistantToolInterface> */
    public function all(): array
    {
        return [new WholesaleExecutiveTool($this->service)];
    }
}
