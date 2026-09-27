<?php

namespace App\Support;

final readonly class ResolvedTenantContext
{
    public function __construct(
        public int $userId,
        public string $channel,
        public ?int $storeId,
        public bool $supportAccess = false,
    ) {}

    public function isRetail(): bool
    {
        return $this->channel === 'b2c';
    }

    public function isWholesale(): bool
    {
        return $this->channel === 'b2b';
    }

    public function requiresStore(): int
    {
        abort_if($this->storeId === null, 409, 'A store context is required.');

        return $this->storeId;
    }

    public function ownsStore(int $storeId): bool
    {
        return $this->storeId !== null && $this->storeId === $storeId;
    }
}
