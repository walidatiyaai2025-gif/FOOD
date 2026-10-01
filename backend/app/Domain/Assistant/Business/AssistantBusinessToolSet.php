<?php

namespace App\Domain\Assistant\Business;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final class AssistantBusinessToolSet
{
    public function __construct(private readonly AssistantBusinessReadService $service) {}

    /**
     * @return list<AssistantToolInterface>
     */
    public function all(): array
    {
        return array_map(
            fn (string $key): AssistantToolInterface => new AssistantBusinessTool($this->service, $key),
            AssistantBusinessTool::KEYS,
        );
    }
}
