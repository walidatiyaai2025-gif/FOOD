<?php

namespace App\Domain\Assistant\Security;

use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
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
    ) {}

    public function execute(
        string $toolKey,
        AssistantToolRequest $request,
        string $correlationId,
    ): AssistantToolResult {
        abort_unless($this->tools->has($toolKey), 422);

        $actor = User::query()->find($request->actorUserId);
        abort_unless($actor instanceof User && (bool) $actor->is_active, 403);

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

        $dataClass = $this->dataClass($request);
        $retailerStoreId = null;

        if ($dataClass === 'SELL_OUT') {
            $retailerStoreId = $this->positiveInt(
                $request->entities['retailer_store_id']
                    ?? $request->context['retailer_store_id']
                    ?? null,
            );
            if ($retailerStoreId === null) {
                throw ValidationException::withMessages([
                    'retailer_store_id' => ['A retailer store is required for sell-out access.'],
                ]);
            }

            $wholesaleStoreId = $this->positiveInt(
                $request->entities['wholesale_store_id']
                    ?? $request->context['wholesale_store_id']
                    ?? ($request->channel === 'b2b' ? $request->storeId : null),
            );
            $purpose = $request->entities['purpose']
                ?? $request->context['purpose']
                ?? RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS;

            $this->sellOutPolicy->assertCanRead(
                $actor,
                $retailerStoreId,
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
                'store_id' => $retailerStoreId ?? $request->storeId,
                'tool' => $toolKey,
                'channel' => $request->channel,
                'data_class' => $dataClass,
                'correlation_id' => $correlationId,
            ],
        );

        return $this->tools->get($toolKey)->execute($request);
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

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
