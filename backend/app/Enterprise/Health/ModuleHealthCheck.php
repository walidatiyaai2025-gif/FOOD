<?php

namespace App\Enterprise\Health;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ModuleHealthCheck
{
    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public function __construct(
        public string $key,
        public ModuleHealthStatus $status,
        public CarbonImmutable $checkedAt,
        public ?CarbonImmutable $staleAt = null,
        public ?string $message = null,
        public array $metadata = [],
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('Module health check key is required.');
        }

        if (in_array($this->status, [
            ModuleHealthStatus::HEALTHY,
            ModuleHealthStatus::DEGRADED,
            ModuleHealthStatus::ERROR,
        ], true) === false) {
            throw new InvalidArgumentException('Probe checks may only report HEALTHY, DEGRADED or ERROR.');
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public static function healthy(
        string $key,
        ?CarbonImmutable $checkedAt = null,
        ?CarbonImmutable $staleAt = null,
        array $metadata = [],
    ): self {
        return new self(
            key: $key,
            status: ModuleHealthStatus::HEALTHY,
            checkedAt: $checkedAt ?? CarbonImmutable::now('UTC'),
            staleAt: $staleAt,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public static function degraded(
        string $key,
        string $message,
        array $metadata = [],
        ?CarbonImmutable $checkedAt = null,
        ?CarbonImmutable $staleAt = null,
    ): self {
        return new self(
            key: $key,
            status: ModuleHealthStatus::DEGRADED,
            checkedAt: $checkedAt ?? CarbonImmutable::now('UTC'),
            staleAt: $staleAt,
            message: $message,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public static function error(
        string $key,
        string $message = 'Health probe failed.',
        array $metadata = [],
    ): self {
        return new self(
            key: $key,
            status: ModuleHealthStatus::ERROR,
            checkedAt: CarbonImmutable::now('UTC'),
            message: $message,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public static function timeout(
        string $key,
        array $metadata = [],
    ): self {
        return self::degraded($key, 'Health probe timed out.', [
            ...$metadata,
            'timed_out' => true,
        ]);
    }

    public function isStale(?CarbonImmutable $now = null): bool
    {
        if ($this->staleAt === null) {
            return false;
        }

        return ($now ?? CarbonImmutable::now('UTC'))->greaterThanOrEqualTo($this->staleAt);
    }
}
