<?php

namespace App\Domain\Assistant\Executive;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;

final readonly class WholesaleExecutiveTool implements AssistantToolInterface
{
    public function __construct(private WholesaleExecutiveIntelligenceService $service) {}

    public function key(): string
    {
        return 'executive.wholesale';
    }

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        return $this->service->execute($request);
    }
}
