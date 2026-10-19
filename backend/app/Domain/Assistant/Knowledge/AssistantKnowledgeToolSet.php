<?php

namespace App\Domain\Assistant\Knowledge;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final class AssistantKnowledgeToolSet
{
    public function __construct(private readonly AssistantKnowledgeIndex $index) {}

    /**
     * @return list<AssistantToolInterface>
     */
    public function all(): array
    {
        return [
            new AssistantKnowledgeTool($this->index),
        ];
    }
}
