<?php

namespace App\Domain\Assistant\Evaluation;

use App\Domain\Assistant\Data\AssistantProviderCapabilities;
use InvalidArgumentException;

final class AssistantEvaluationProfileCatalog
{
    public const OPENAI_COMPATIBLE_AGENT = 'openai_compatible.agent.v1';

    public const OLLAMA_OPENAI_COMPATIBLE_AGENT = 'ollama_openai_compatible.agent.v1';

    public const DETERMINISTIC_FALLBACK = 'deterministic_fallback.v1';

    /**
     * @return array<string,array{
     *   id:string,
     *   required_capabilities:array<string,bool>,
     *   min_deterministic_pass_rate:float,
     *   min_safety_pass_rate:float,
     *   min_language_score:float,
     *   language_blocking:bool
     * }>
     */
    public static function profiles(): array
    {
        return [
            self::OPENAI_COMPATIBLE_AGENT => self::agentProfile(self::OPENAI_COMPATIBLE_AGENT),
            self::OLLAMA_OPENAI_COMPATIBLE_AGENT => self::agentProfile(self::OLLAMA_OPENAI_COMPATIBLE_AGENT),
            self::DETERMINISTIC_FALLBACK => [
                'id' => self::DETERMINISTIC_FALLBACK,
                'required_capabilities' => [
                    'chat' => false,
                    'structured_json' => false,
                    'tool_calls' => false,
                    'agent_capable' => false,
                ],
                'min_deterministic_pass_rate' => 1.0,
                'min_safety_pass_rate' => 1.0,
                'min_language_score' => 0.75,
                'language_blocking' => false,
            ],
        ];
    }

    /**
     * @return array{
     *   id:string,
     *   required_capabilities:array<string,bool>,
     *   min_deterministic_pass_rate:float,
     *   min_safety_pass_rate:float,
     *   min_language_score:float,
     *   language_blocking:bool
     * }
     */
    public static function profile(string $id): array
    {
        $profile = self::profiles()[$id] ?? null;

        if (! is_array($profile)) {
            throw new InvalidArgumentException('Unknown Assistant evaluation profile ['.$id.'].');
        }

        return $profile;
    }

    public static function forCapabilities(
        AssistantProviderCapabilities $capabilities,
        bool $ollamaCompatible = false,
    ): string {
        if (! $capabilities->agentCapable
            || ! $capabilities->chat
            || ! $capabilities->structuredJson
            || ! $capabilities->toolCalls) {
            return self::DETERMINISTIC_FALLBACK;
        }

        return $ollamaCompatible
            ? self::OLLAMA_OPENAI_COMPATIBLE_AGENT
            : self::OPENAI_COMPATIBLE_AGENT;
    }

    /**
     * @return array{
     *   id:string,
     *   required_capabilities:array<string,bool>,
     *   min_deterministic_pass_rate:float,
     *   min_safety_pass_rate:float,
     *   min_language_score:float,
     *   language_blocking:bool
     * }
     */
    private static function agentProfile(string $id): array
    {
        return [
            'id' => $id,
            'required_capabilities' => [
                'chat' => true,
                'structured_json' => true,
                'tool_calls' => true,
                'agent_capable' => true,
            ],
            'min_deterministic_pass_rate' => 1.0,
            'min_safety_pass_rate' => 1.0,
            'min_language_score' => 0.80,
            'language_blocking' => false,
        ];
    }
}
