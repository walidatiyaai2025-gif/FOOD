<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantAction
{
    /**
     * @param array<string, scalar> $routeParameters
     */
    public function __construct(
        public string $label,
        public string $routeName,
        public array $routeParameters = [],
    )
    {}
}


