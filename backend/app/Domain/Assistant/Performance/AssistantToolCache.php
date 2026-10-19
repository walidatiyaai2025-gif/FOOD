<?php

namespace App\Domain\Assistant\Performance;

use App\Domain\Assistant\Data\AiPrincipalContext;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolDefinition;
use Illuminate\Support\Facades\Cache;

final class AssistantToolCache
{
    public function get(
        AssistantToolDefinition $definition,
        AssistantToolRequest $request,
        AiPrincipalContext $principal,
    ): ?AssistantToolResult {
        $ttl = $this->ttlSeconds($definition);

        if ($ttl <= 0) {
            return null;
        }

        $cached = Cache::get($this->key($definition, $request, $principal));

        return $cached instanceof AssistantToolResult ? $cached : null;
    }

    public function put(
        AssistantToolDefinition $definition,
        AssistantToolRequest $request,
        AiPrincipalContext $principal,
        AssistantToolResult $result,
    ): int {
        $ttl = $this->ttlSeconds($definition);

        if ($ttl > 0) {
            Cache::put($this->key($definition, $request, $principal), $result, $ttl);
        }

        return $ttl;
    }

    public function ttlSeconds(AssistantToolDefinition $definition): int
    {
        if (! (bool) config('assistant.performance.tool_cache_enabled', true)
            || $definition->mode !== 'READ') {
            return 0;
        }

        $sources = (array) config('assistant.performance.cacheable_sources', []);
        if (! in_array($definition->source, $sources, true)) {
            return 0;
        }

        $staleAfterMinutes = (int) ($definition->freshness['stale_after_minutes'] ?? 0);
        if ($staleAfterMinutes <= 0) {
            return 0;
        }

        $default = max(1, (int) config('assistant.performance.tool_cache_seconds', 45));
        $maximum = max(1, (int) config('assistant.performance.tool_cache_max_seconds', 120));

        return min($default, $maximum, $staleAfterMinutes * 60);
    }

    public function key(
        AssistantToolDefinition $definition,
        AssistantToolRequest $request,
        AiPrincipalContext $principal,
    ): string {
        $payload = $this->canonicalize([
            'tool' => $definition->key,
            'schema_version' => $definition->schemaVersion,
            'actor_user_id' => $principal->actorUserId,
            'business_scope' => $principal->businessScope,
            'channel' => $principal->channel,
            'active_store_id' => $principal->activeStoreId,
            'permitted_store_ids' => $principal->permittedStoreIds,
            'permission_codes' => $principal->permissionCodes,
            'retailer_data_grants' => $principal->retailerDataGrants,
            'request_channel' => $request->channel,
            'request_store_id' => $request->storeId,
            'entities' => $request->entities,
        ]);

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return 'assistant:tool-cache:'.hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
