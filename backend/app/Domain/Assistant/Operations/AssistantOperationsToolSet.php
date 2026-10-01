<?php

namespace App\Domain\Assistant\Operations;

use App\Domain\Assistant\Contracts\AssistantToolInterface;

final class AssistantOperationsToolSet
{
    public function __construct(private readonly AssistantOperationsReadService $service) {}

    /**
     * @return list<AssistantToolInterface>
     */
    public function all(): array
    {
        return array_map(
            fn (string $key): AssistantToolInterface => new AssistantOperationsTool($this->service, $key),
            AssistantOperationsTool::KEYS,
        );
    }
}
