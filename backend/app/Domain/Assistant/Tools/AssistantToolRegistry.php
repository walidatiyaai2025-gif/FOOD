<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use InvalidArgumentException;

class AssistantToolRegistry
{
    /** @var array<string, AssistantToolInterface> */
    private array $tools = [];

    /** @var array<string, AssistantToolDefinition> */
    private array $definitions = [];

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
        $this->definitions[$key] = AssistantToolDefinitionCatalog::forKey($key);
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

    public function definition(string $key): AssistantToolDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException("Assistant tool definition [{$key}] is not registered.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->tools);
        sort($keys);

        return $keys;
    }

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        $definitions = $this->definitions;
        ksort($definitions);

        return array_map(
            static fn (AssistantToolDefinition $definition): array => $definition->toArray(),
            array_values($definitions),
        );
    }

    /** @return list<array<string,mixed>> */
    public function providerTools(string $locale = 'en'): array
    {
        $definitions = $this->definitions;
        ksort($definitions);

        return array_map(
            static fn (AssistantToolDefinition $definition): array => $definition->providerTool($locale),
            array_values($definitions),
        );
    }
}
