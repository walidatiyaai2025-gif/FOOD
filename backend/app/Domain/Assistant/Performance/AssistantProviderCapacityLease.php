<?php

namespace App\Domain\Assistant\Performance;

use Illuminate\Support\Facades\Cache;

final class AssistantProviderCapacityLease
{
    /** @param array<string,string> $slots key => owner */
    public function __construct(
        private array $slots,
        public readonly bool $acquired,
    ) {}

    public function release(): void
    {
        if (! $this->acquired || $this->slots === []) {
            return;
        }

        foreach (array_reverse($this->slots, true) as $key => $owner) {
            if (Cache::get($key) === $owner) {
                Cache::forget($key);
            }
        }

        $this->slots = [];
    }

    public function __destruct()
    {
        $this->release();
    }
}
