<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ProviderTransportException;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\WhatsAppCallingService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppCall;
use App\Services\OperationalTenantScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WhatsAppCallController extends Controller
{
    public function __construct(
        private readonly WhatsAppCallingService $calls,
        private readonly RuntimePolicy $policy,
        private readonly OperationalTenantScope $scope,
    ) {}

    public function feed(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($this->policy->allowsCallWebhooks(), 404);

        $allowedStoreIds = $this->scope->allowedStoreIds($actor, 'whatsapp.calls.view');
        abort_unless($actor->hasRole('SUPER_ADMIN') || $allowedStoreIds !== [], 403);

        $query = DB::table('whats_app_calls as calls')
            ->leftJoin('whats_app_conversations as conversations', 'conversations.id', '=', 'calls.conversation_id')
            ->where('calls.updated_at', '>=', now()->subMinutes(15))
            ->orderByDesc('calls.updated_at')
            ->limit(30)
            ->select('calls.id');

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $query->whereIn('calls.store_id', $allowedStoreIds);

            if (! $actor->hasPermission('whatsapp.customer_service.supervise')) {
                $query->where(function (Builder $visibility) use ($actor): void {
                    $visibility
                        ->where(function (Builder $call) use ($actor): void {
                            $call->whereNull('calls.agent_user_id')
                                ->orWhere('calls.agent_user_id', $actor->getKey());
                        })
                        ->where(function (Builder $conversation) use ($actor): void {
                            $conversation->whereNull('conversations.primary_agent_id')
                                ->orWhere('conversations.primary_agent_id', $actor->getKey());
                        });
                });
            }
        }

        $ids = $query->pluck('calls.id')->map(fn ($id): int => (int) $id)->all();
        $rows = WhatsAppCall::query()
            ->whereIn('id', $ids)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (WhatsAppCall $call): array => [
                'id' => (int) $call->id,
                'public_id' => (string) $call->public_id,
                'conversation_id' => $call->conversation_id === null ? null : (int) $call->conversation_id,
                'provider_call_id' => $call->provider_call_id,
                'direction' => (string) $call->direction,
                'status' => (string) $call->status,
                'event' => $call->event,
                'from_phone' => $call->from_phone,
                'to_phone' => $call->to_phone,
                'agent_user_id' => $call->agent_user_id === null ? null : (int) $call->agent_user_id,
                'remote_sdp_type' => $call->remote_sdp_type,
                'remote_sdp' => $call->remote_sdp,
                'started_at' => $call->started_at?->toIso8601String(),
                'answered_at' => $call->answered_at?->toIso8601String(),
                'ended_at' => $call->ended_at?->toIso8601String(),
                'duration_seconds' => $call->duration_seconds,
                'contact_name' => is_array($call->metadata)
                    ? ($call->metadata['contact_name'] ?? null)
                    : null,
            ]);

        return response()->json([
            'ok' => true,
            'calls' => $rows,
            'ice_servers' => array_values((array) config('whatsapp.webrtc.ice_servers', [])),
            'inbound_enabled' => $this->policy->allowsInboundCalls(),
        ]);
    }

    public function permission(Request $request, int $conversation): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->providerResponse(
            fn (): array => $this->calls->permissionForConversation($conversation, $actor),
        );
    }

    public function requestPermission(Request $request, int $conversation): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1024'],
        ]);

        return $this->providerResponse(
            fn (): array => $this->calls->requestPermission(
                $conversation,
                $actor,
                $data['reason'] ?? null,
            ),
        );
    }

    public function start(Request $request, int $conversation): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'sdp' => ['required', 'string', 'max:250000'],
        ]);

        return $this->providerResponse(function () use ($conversation, $actor, $data): array {
            $call = $this->calls->startOutbound(
                $conversation,
                $actor,
                (string) $data['sdp'],
            );

            return [
                'ok' => true,
                'call_id' => (int) $call->id,
                'public_id' => (string) $call->public_id,
                'provider_call_id' => $call->provider_call_id,
                'status' => (string) $call->status,
            ];
        });
    }

    public function action(Request $request, int $call): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['pre_accept', 'accept', 'reject', 'terminate'])],
            'sdp' => ['nullable', 'string', 'max:250000'],
        ]);

        $row = WhatsAppCall::query()->findOrFail($call);
        if ($row->direction === 'USER_INITIATED'
            && in_array($data['action'], ['pre_accept', 'accept'], true)
            && ! $this->policy->allowsInboundCalls()) {
            throw ValidationException::withMessages([
                'call' => ['Inbound WhatsApp calling is disabled by the current runtime policy.'],
            ]);
        }

        return $this->providerResponse(function () use ($row, $actor, $data): array {
            $updated = $this->calls->signal(
                $row,
                $actor,
                (string) $data['action'],
                isset($data['sdp']) ? (string) $data['sdp'] : null,
            );

            return [
                'ok' => true,
                'call_id' => (int) $updated->id,
                'status' => (string) $updated->status,
            ];
        });
    }

    /** @param callable():array<string,mixed> $callback */
    private function providerResponse(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ProviderTransportException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], $exception->retryable ? 503 : 422);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
