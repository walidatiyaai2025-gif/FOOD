<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;

interface AssistantToolInterface
{
    public function key(): string;

    public function execute(AssistantToolRequest $request): AssistantToolResult;
}
