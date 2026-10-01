<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use InvalidArgumentException;

class AssistantToolRegistry
{
    /** @var array<string, AssistantToolInterface> */
    private array $tools = [];

    /** @param iterable<AssistantToolInterface> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AssistantToolInterface $tool): void
    {
        $key = trim($tool->key());

        if ($key === '') {
            throw new InvalidArgumentException('Assistant tool keys must not be empty.');
        }

        if (isset($this->tools[$key])) {
            throw new InvalidArgumentException("Assistant tool [{$key}] is already registered.");
        }

        $this->tools[$key] = $tool;
    }

    public function has(string $key): bool
    {
        return isset($this->tools[$key]);
    }

    public function get(string $key): AssistantToolInterface
    {
        return $this->tools[$key]
            ?? throw new InvalidArgumentException("Assistant tool [{$key}] is not registered.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->tools);
        sort($keys);

        return $keys;
    }
}
