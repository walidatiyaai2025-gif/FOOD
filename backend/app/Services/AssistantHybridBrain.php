<?php

namespace App\Services;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Conversation\DeterministicBrain;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;

final class AssistantHybridBrain implements AssistantBrainInterface
{
    public function __construct(
        private readonly DeterministicBrain $deterministic,
    ) {}

    public function respond(AssistantBrainRequest $request): AssistantBrainResult
    {
        // The deterministic brain remains the always-available parser/fallback.
        // External AI planning and synthesis are owned by AssistantAgentOrchestrator
        // so one turn cannot consume duplicate provider calls through two routing layers.
        return $this->deterministic->respond($request);
    }
}
