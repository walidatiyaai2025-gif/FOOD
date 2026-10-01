<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;

interface AssistantBrainInterface
{
    public function respond(AssistantBrainRequest $request): AssistantBrainResult;
}
