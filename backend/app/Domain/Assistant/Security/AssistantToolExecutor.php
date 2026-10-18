<?php

namespace App\Domain\Assistant\Security;

use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolDefinition;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

final class AssistantToolExecutor
{
    public function __construct(
        private readonly AssistantToolRegistry $tools,
        private readonly AiPrincipalContextResolver $principals,
        private readonly RetailerSellOutAccessPolicy $sellOutPolicy,
        private readonly AuditLogger $audit,
        private readonly ?AssistantAiSecurityGuardrails $guardrails = null,
    ) {}

    public function execute(
        string $toolKey,
        AssistantToolRequest $request,
        string $correlationId,
    ): AssistantToolResult {
        abort_unless($this->tools->has($toolKey), 422);

        $definition = $this->tools->definition($toolKey);
        $definition->validate($request);

        $actor = User::query()->find($request->actorUserId);
        abort_unless($actor instanceof User && (bool) $actor->is_active, 403);

        $guardrails = $this->guardrails ?? app(AssistantAiSecurityGuardrails::class);
        $guardrails->assertToolAllowed($definition, $actor, $correlationId);
        $guardrails->assertToolArgumentsSafe($request->entities, $actor, $toolKey, $correlationId);

        $principalContext = $request->context;
        if ($request->channel !== null) {
            $principalContext['channel'] = $request->channel;
        }
        if ($request->storeId !== null) {
            $principalContext['store_id'] = $request->storeId;
        }

        $principal = $this->principals->resolve(
            $actor,
            $request->locale,
            $principalContext,
            $correlationId,
        );

        if ($principal->permittedStoreIds === []) {
            abort(403);
        }

        $this->assertPermissions($definition, $principal->permissionCodes);

        $dataClass = $this->dataClass($request) ?? $definition->dataClass;
        $retailerStoreIds = [];

        if ($definition->sellOutConsent || $dataClass === 'SELL_OUT') {
            $retailerStoreIds = $this->retailerStoreIds($request);

            if ($retailerStoreIds === [] && strtolower((string) $request->channel) === 'b2c' && $request->storeId !== null) {
                $retailerStoreIds = [$request->storeId];
            }

            if ($retailerStoreIds === []) {
                throw ValidationException::withMessages([
                    'retailer_store_ids' => ['At least one retailer store is required for sell-out access.'],
                ]);
            }

            $wholesaleStoreId = $this->positiveInt(
                $request->entities['wholesale_store_id']
                    ?? $request->context['wholesale_store_id']
                    ?? (strtolower((string) $request->channel) === 'b2b' ? $request->storeId : null),
            );
            $purpose = $request->entities['purpose']
                ?? $request->context['purpose']
                ?? RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS;

            $this->sellOutPolicy->assertCanReadMany(
                $actor,
                $retailerStoreIds,
                $wholesaleStoreId,
                is_string($purpose) ? $purpose : RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS,
                $request->locale,
            );
        }

        $this->audit->record(
            'assistant.tool.read',
            $actor,
            null,
            null,
            [
                'store_id' => $retailerStoreIds[0] ?? $request->storeId,
                'tool' => $toolKey,
                'tool_schema_version' => $definition->schemaVersion,
                'channel' => $request->channel,
                'data_class' => $dataClass,
                'source' => $definition->source,
                'row_limit' => $definition->rowLimit,
                'correlation_id' => $correlationId,
            ],
        );

        $startedAt = hrtime(true);
        $result = $this->tools->get($toolKey)->execute($request);
        $result = $guardrails->sanitizeToolResult($result, $actor, $toolKey, $correlationId);
        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return new AssistantToolResult(
            data: [
                ...$result->data,
                '_tool' => [
                    'key' => $definition->key,
                    'schema_version' => $definition->schemaVersion,
                    'mode' => $definition->mode,
                    'source' => $definition->source,
                    'data_class' => $dataClass,
                    'freshness' => $definition->freshness,
                    'row_limit' => $definition->rowLimit,
                    'timeout_ms' => $definition->timeoutMs,
                    'elapsed_ms' => $elapsedMs,
                    'correlation_id' => $correlationId,
                    'model_sql_allowed' => false,
                ],
            ],
            cards: $result->cards,
            actions: $result->actions,
            references: $result->references,
        );
    }

    /** @param list<string> $principalPermissions */
    private function assertPermissions(AssistantToolDefinition $definition, array $principalPermissions): void
    {
        if ($definition->requiredPermissions === []) {
            return;
        }

        $matches = array_values(array_intersect($definition->requiredPermissions, $principalPermissions));

        if ($definition->permissionMode === 'any') {
            abort_if($matches === [], 403);

            return;
        }

        abort_unless(count($matches) === count($definition->requiredPermissions), 403);
    }

    private function dataClass(AssistantToolRequest $request): ?string
    {
        $value = $request->entities['data_class']
            ?? $request->entities['dataset_class']
            ?? $request->entities['fact_class']
            ?? $request->context['data_class']
            ?? $request->context['dataset_class']
            ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }

    /** @return list<int> */
    private function retailerStoreIds(AssistantToolRequest $request): array
    {
        $values = $request->entities['retailer_store_ids'] ?? null;
        if (is_array($values)) {
            $ids = collect($values)
                ->map(fn ($value): ?int => $this->positiveInt($value))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (count($ids) !== count($values) || count($ids) > 100) {
                throw ValidationException::withMessages([
                    'retailer_store_ids' => ['Retailer store ids must be unique positive integers with a maximum of 100.'],
                ]);
            }

            return $ids;
        }

        $single = $this->positiveInt(
            $request->entities['retailer_store_id']
                ?? $request->context['retailer_store_id']
                ?? null,
        );

        return $single === null ? [] : [$single];
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
