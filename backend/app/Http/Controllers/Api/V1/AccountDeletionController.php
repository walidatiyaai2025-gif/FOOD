<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountDeletionController extends Controller
{
    public function store(
        Request $request,
        AccountDeletionService $deletion,
        AuditLogger $audit,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'accepted'],
        ]);

        $deletionRequest = $deletion->request($user, $data['password']);

        $audit->record(
            'account_deletion.requested',
            $user,
            $deletionRequest,
            null,
            [
                'app' => $deletionRequest->app,
                'status' => $deletionRequest->status,
                'retention_context' => $deletionRequest->retention_context,
            ],
            $request,
        );

        return response()->json([
            'data' => $this->payload($deletionRequest),
        ], $deletionRequest->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $deletionRequest = $deletion->status($user);

        return response()->json([
            'data' => $deletionRequest instanceof AccountDeletionRequest
                ? $this->payload($deletionRequest)
                : null,
        ]);
    }

    private function payload(AccountDeletionRequest $request): array
    {
        return [
            'id' => (int) $request->getKey(),
            'app' => (string) $request->app,
            'status' => (string) $request->status,
            'block_reason' => $request->block_reason,
            'retention' => $request->retention_context ?? [],
            'requested_at' => $request->created_at?->toAtomString(),
            'anonymized_at' => $request->anonymized_at?->toAtomString(),
            'completed_at' => $request->completed_at?->toAtomString(),
        ];
    }
}
