<?php

namespace App\Domain\Assistant\Operations;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use InvalidArgumentException;

final class AssistantOperationsTool implements AssistantToolInterface
{
    public const KEYS = [
        'orders.late',
        'orders.cancelled',
        'cancellations.summary',
        'drivers.status',
        'drivers.assignments',
        'inventory.alerts',
        'brief.daily',
    ];

    public function __construct(
        private readonly AssistantOperationsReadService $service,
        private readonly string $toolKey,
    ) {
        if (! in_array($toolKey, self::KEYS, true)) {
            throw new InvalidArgumentException("Unsupported Assistant operations tool [{$toolKey}].");
        }
    }

    public function key(): string
    {
        return $this->toolKey;
    }

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        return $this->service->execute($this->toolKey, $request);
    }
}
