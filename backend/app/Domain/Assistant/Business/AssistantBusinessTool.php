<?php

namespace App\Domain\Assistant\Business;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use InvalidArgumentException;

final class AssistantBusinessTool implements AssistantToolInterface
{
    public const KEYS = [
        'sales.summary',
        'sales.compare',
        'orders.summary',
        'orders.lookup',
        'stores.summary',
        'stores.compare',
        'customers.summary',
        'customers.activity',
        'products.performance',
    ];

    public function __construct(
        private readonly AssistantBusinessReadService $service,
        private readonly string $toolKey,
    ) {
        if (! in_array($toolKey, self::KEYS, true)) {
            throw new InvalidArgumentException("Unsupported Assistant business tool [{$toolKey}].");
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
