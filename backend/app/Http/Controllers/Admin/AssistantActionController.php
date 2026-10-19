<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Assistant\Actions\AssistantActionApprovalService;
use App\Domain\Assistant\Actions\Models\AssistantActionApproval;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AssistantRuntimeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssistantActionController extends Controller
{
    public function __construct(
        private readonly AssistantActionApprovalService $actions,
        private readonly AssistantRuntimeSettings $runtime,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'action_key' => ['required', Rule::in([
                AssistantActionApprovalService::ACTION_PURCHASE_REQUEST_DRAFT,
            ])],
            'store_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'payload' => ['required', 'array'],
        ]);

        $approval = $this->actions->create(
            $actor,
            $data,
            (string) ($data['locale'] ?? $actor->locale ?? 'en'),
            $request->header('X-Correlation-Id'),
        );

        return response()->json(['data' => $this->payload($approval)], 201);
    }

    public function show(Request $request, string $actionId): JsonResponse
    {
        $actor = $this->actor($request);
        $approval = $this->actions->findOwned($actor, $actionId);

        return response()->json(['data' => $this->payload($approval)]);
    }

    public function approve(Request $request, string $actionId): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'payload_hash' => ['required', 'string', 'size:64'],
        ]);
        $approval = $this->actions->findOwned($actor, $actionId);
        $approval = $this->actions->approve(
            $actor,
            $approval,
            (string) $data['payload_hash'],
            true,
        );

        return response()->json(['data' => $this->payload($approval)]);
    }

    public function execute(Request $request, string $actionId): JsonResponse
    {
        $actor = $this->actor($request);
        $approval = $this->actions->findOwned($actor, $actionId);
        $approval = $this->actions->execute($actor, $approval);

        return response()->json(['data' => $this->payload($approval)]);
    }

    private function actor(Request $request): User
    {
        abort_unless($this->runtime->enabled(), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless(
            $actor->hasPermission('assistant.use')
            || $actor->storeRoleAssignments()
                ->whereHas('role', fn ($role) => $role
                    ->where('roles.is_active', true)
                    ->whereHas('permissions', fn ($permission) => $permission
                        ->where('permissions.code', 'assistant.use')))
                ->exists(),
            403,
        );

        return $actor;
    }

    /** @return array<string,mixed> */
    private function payload(AssistantActionApproval $approval): array
    {
        return [
            'id' => $approval->public_id,
            'action_key' => $approval->action_key,
            'action_version' => $approval->action_version,
            'status' => $approval->status,
            'channel' => $approval->channel,
            'store_id' => (int) $approval->store_id,
            'payload_hash' => $approval->payload_hash,
            'preview' => $approval->preview,
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'approved_at' => $approval->approved_at?->toIso8601String(),
            'executed_at' => $approval->executed_at?->toIso8601String(),
            'result' => $approval->result,
            'approval_required' => true,
            'model_text_is_approval' => false,
        ];
    }
}
