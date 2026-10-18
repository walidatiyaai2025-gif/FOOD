<?php

namespace App\Services;

final class AssistantModelRegistry
{
    public const USE_CASES = [
        'intent_routing',
        'agent_planning',
        'grounded_synthesis',
    ];

    public function __construct(private readonly AssistantProviderSettings $settings) {}

    /**
     * @return array<int,array{
     *   id:string,
     *   role:string,
     *   use_cases:array<int,string>,
     *   temperature:float,
     *   max_tokens:int,
     *   context_window_tokens:int
     * }>
     */
    public function snapshot(): array
    {
        $config = $this->settings->snapshot();
        $models = [];

        foreach ($this->settings->routingModels() as $index => $model) {
            $models[] = [
                'id' => $model,
                'role' => $index === 0 ? 'primary' : 'fallback',
                'use_cases' => self::USE_CASES,
                'temperature' => $config['temperature'],
                'max_tokens' => $config['max_tokens'],
                'context_window_tokens' => $config['context_window_tokens'],
            ];
        }

        return $models;
    }

    /**
     * @return array<int,string>
     */
    public function routingOrder(string $useCase): array
    {
        if (! in_array($useCase, self::USE_CASES, true)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $model): string => $model['id'],
            $this->snapshot(),
        ));
    }
}
