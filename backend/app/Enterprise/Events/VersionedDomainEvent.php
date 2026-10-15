<?php

namespace App\Enterprise\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class VersionedDomainEvent
{
    /**
     * @param array<string, int|string|null> $scope
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $version,
        public CarbonImmutable $occurredAt,
        public array $scope,
        public ?string $correlationId,
        public ?string $causationId,
        public array $payload,
    ) {
        if (! Str::isUuid($this->id)) {
            throw new InvalidArgumentException('Domain event id must be a UUID.');
        }

        if ($this->type === '' || preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $this->type) !== 1) {
            throw new InvalidArgumentException('Domain event type is invalid.');
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException('Domain event version must be >= 1.');
        }

        foreach (array_keys($this->scope) as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Domain event scope keys must be non-empty strings.');
            }
        }
    }

    /**
     * @param array<string, int|string|null> $scope
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $type,
        int $version,
        array $scope,
        array $payload,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): self {
        return new self(
            id: (string) Str::uuid(),
            type: $type,
            version: $version,
            occurredAt: CarbonImmutable::now('UTC'),
            scope: $scope,
            correlationId: $correlationId,
            causationId: $causationId,
            payload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $scope = $values['scope'] ?? [];
        $payload = $values['payload'] ?? [];

        if (! is_array($scope) || ! is_array($payload)) {
            throw new InvalidArgumentException('Domain event scope and payload must be objects.');
        }

        return new self(
            id: (string) ($values['id'] ?? ''),
            type: (string) ($values['type'] ?? ''),
            version: (int) ($values['version'] ?? 0),
            occurredAt: CarbonImmutable::parse((string) ($values['occurred_at'] ?? ''), 'UTC'),
            scope: $scope,
            correlationId: isset($values['correlation_id']) ? (string) $values['correlation_id'] : null,
            causationId: isset($values['causation_id']) ? (string) $values['causation_id'] : null,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt->utc()->toIso8601String(),
            'scope' => $this->scope,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'payload' => $this->payload,
        ];
    }
}
