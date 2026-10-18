<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Data\AssistantToolRequest;
use Illuminate\Validation\ValidationException;

final readonly class AssistantToolDefinition
{
    /**
     * @param  array{en:string,ar:string}  $labels
     * @param  list<string>  $requiredPermissions
     * @param  list<string>  $channels
     * @param  array<string,mixed>  $inputSchema
     * @param  array<string,mixed>  $resultSchema
     * @param  array<string,mixed>  $freshness
     */
    public function __construct(
        public string $key,
        public string $schemaVersion,
        public array $labels,
        public string $mode,
        public array $requiredPermissions,
        public string $permissionMode,
        public array $channels,
        public array $inputSchema,
        public array $resultSchema,
        public int $timeoutMs,
        public int $rowLimit,
        public string $dataClass,
        public bool $sellOutConsent,
        public string $source,
        public array $freshness = [],
    ) {}

    public function validate(AssistantToolRequest $request): void
    {
        $channel = $request->channel === null ? null : strtolower($request->channel);
        if ($channel !== null && $this->channels !== [] && ! in_array($channel, $this->channels, true)) {
            throw ValidationException::withMessages([
                'channel' => ["Assistant tool [{$this->key}] does not support channel [{$channel}]."],
            ]);
        }

        $entityStoreId = $this->positiveInt($request->entities['store_id'] ?? null);
        if ($entityStoreId !== null && $request->storeId !== null && $entityStoreId !== $request->storeId) {
            throw ValidationException::withMessages([
                'store_id' => ['Tool arguments cannot override the server-authorized store scope.'],
            ]);
        }

        $entityChannel = $request->entities['channel'] ?? null;
        if (is_string($entityChannel) && $request->channel !== null
            && strtolower(trim($entityChannel)) !== strtolower($request->channel)) {
            throw ValidationException::withMessages([
                'channel' => ['Tool arguments cannot override the server-authorized channel scope.'],
            ]);
        }

        foreach (['actor_user_id', 'permission_codes', 'role_codes', 'permitted_store_ids', 'sql', 'raw_sql', 'query_sql', 'database', 'connection'] as $reserved) {
            if (array_key_exists($reserved, $request->entities)) {
                throw ValidationException::withMessages([
                    $reserved => ['Reserved Assistant execution fields cannot be supplied by tool arguments.'],
                ]);
            }
        }

        $this->validateObject($request->entities, $this->inputSchema, 'entities');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->key,
            'schema_version' => $this->schemaVersion,
            'labels' => $this->labels,
            'mode' => $this->mode,
            'required_permissions' => $this->requiredPermissions,
            'permission_mode' => $this->permissionMode,
            'channels' => $this->channels,
            'input_schema' => $this->inputSchema,
            'result_schema' => $this->resultSchema,
            'timeout_ms' => $this->timeoutMs,
            'row_limit' => $this->rowLimit,
            'data_class' => $this->dataClass,
            'sell_out_consent' => $this->sellOutConsent,
            'source' => $this->source,
            'freshness' => $this->freshness,
            'audit' => [
                'event' => 'assistant.tool.read',
                'redact_arguments' => ['sql', 'raw_sql', 'query_sql', 'database', 'connection'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function providerTool(string $locale = 'en'): array
    {
        $locale = strtolower($locale) === 'ar' ? 'ar' : 'en';

        return [
            'type' => 'function',
            'function' => [
                'name' => str_replace('.', '__', $this->key),
                'description' => $this->labels[$locale],
                'parameters' => $this->inputSchema,
            ],
            'x-foodex' => [
                'tool_key' => $this->key,
                'schema_version' => $this->schemaVersion,
                'mode' => $this->mode,
                'required_permissions' => $this->requiredPermissions,
                'permission_mode' => $this->permissionMode,
                'channels' => $this->channels,
                'timeout_ms' => $this->timeoutMs,
                'row_limit' => $this->rowLimit,
                'data_class' => $this->dataClass,
                'sell_out_consent' => $this->sellOutConsent,
                'source' => $this->source,
            ],
        ];
    }

    /** @param array<string,mixed> $value
     * @param  array<string,mixed>  $schema
     */
    private function validateObject(array $value, array $schema, string $path): void
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $key) {
            if (is_string($key) && ! array_key_exists($key, $value)) {
                throw ValidationException::withMessages([
                    "{$path}.{$key}" => ["Required tool argument [{$key}] is missing."],
                ]);
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $key) {
                if (! array_key_exists($key, $properties)) {
                    throw ValidationException::withMessages([
                        "{$path}.{$key}" => ["Unknown tool argument [{$key}] is not allowed."],
                    ]);
                }
            }
        }

        foreach ($value as $key => $item) {
            if (! isset($properties[$key]) || ! is_array($properties[$key])) {
                continue;
            }

            $this->validateValue($item, $properties[$key], "{$path}.{$key}");
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateValue(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? null;

        $validType = match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            null => true,
            default => false,
        };

        if (! $validType) {
            throw ValidationException::withMessages([
                $path => ["Tool argument [{$path}] must be {$type}."],
            ]);
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw ValidationException::withMessages([
                $path => ["Tool argument [{$path}] has an unsupported value."],
            ]);
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] is below the minimum."]]);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] exceeds the maximum."]]);
            }
        }

        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] is too long."]]);
            }
            if (($schema['format'] ?? null) === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] must use YYYY-MM-DD."]]);
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] contains too many items."]]);
            }
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] contains too few items."]]);
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->validateValue($item, $schema['items'], "{$path}.{$index}");
                }
            }

            if (($schema['uniqueItems'] ?? false) === true && count($value) !== count(array_unique($value, SORT_REGULAR))) {
                throw ValidationException::withMessages([$path => ["Tool argument [{$path}] must contain unique values."]]);
            }
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
