<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AssistantSafeActionTool implements AssistantToolInterface
{
    public function __construct(private readonly AssistantActionApprovalService $actions) {}

    public function key(): string
    {
        return AssistantActionApprovalService::ACTION_PURCHASE_REQUEST_DRAFT;
    }

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        $actor = User::query()->find($request->actorUserId);
        abort_unless($actor instanceof User && (bool) $actor->is_active, 403);

        if ($request->storeId === null || $request->channel === null) {
            throw ValidationException::withMessages([
                'scope' => ['A concrete authorized store and channel are required for an Assistant action draft.'],
            ]);
        }

        $payload = [];
        foreach ([
            'supplier_id',
            'currency_code',
            'needed_by',
            'reason',
            'notes',
            'lines',
        ] as $key) {
            if (array_key_exists($key, $request->entities)) {
                $payload[$key] = $request->entities[$key];
            }
        }

        $approval = $this->actions->create($actor, [
            'action_key' => $this->key(),
            'store_id' => $request->storeId,
            'channel' => $request->channel,
            'payload' => $payload,
        ], $request->locale, $request->context['correlation_id'] ?? null);

        return new AssistantToolResult(data: [
            'approval_required' => true,
            'approval_draft' => [
                'id' => $approval->public_id,
                'action_key' => $approval->action_key,
                'status' => $approval->status,
                'payload_hash' => $approval->payload_hash,
                'preview' => $approval->preview,
                'expires_at' => $approval->expires_at?->toIso8601String(),
                'approve_url' => route('admin.assistant.actions.approve', ['actionId' => $approval->public_id], false),
                'execute_url' => route('admin.assistant.actions.execute', ['actionId' => $approval->public_id], false),
            ],
        ]);
    }
}
