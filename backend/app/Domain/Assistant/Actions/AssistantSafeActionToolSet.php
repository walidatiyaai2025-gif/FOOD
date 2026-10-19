<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final class AssistantSafeActionToolSet
{
    public function __construct(private readonly AssistantActionApprovalService $actions) {}

    /** @return list<AssistantToolInterface> */
    public function all(): array
    {
        return [new AssistantSafeActionTool($this->actions)];
    }
}
