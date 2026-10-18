<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\AgentTurnRequest;
use App\Domain\Assistant\Data\AgentTurnResult;

interface AgentOrchestratorInterface
{
    public function run(AgentTurnRequest $request): AgentTurnResult;
}
