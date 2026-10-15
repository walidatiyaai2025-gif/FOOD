<?php

namespace App\Enterprise\Health;

use Carbon\CarbonImmutable;

final readonly class ModuleHealthReport
{
    /**
     * @param  list<ModuleHealthCheck>  $checks
     */
    public function __construct(
        public string $moduleCode,
        public ModuleHealthStatus $status,
        public CarbonImmutable $checkedAt,
        public array $checks = [],
        public ?string $message = null,
    ) {}

    public static function state(
        string $moduleCode,
        ModuleHealthStatus $status,
        ?string $message = null,
    ): self {
        return new self(
            moduleCode: $moduleCode,
            status: $status,
            checkedAt: CarbonImmutable::now('UTC'),
            message: $message,
        );
    }
}
