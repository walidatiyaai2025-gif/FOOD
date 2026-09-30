<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppPreviewSession;
use App\Models\User;
use App\Services\AppPreviewSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AppPreviewSessionController extends Controller
{
    public function store(Request $request, AppPreviewSessionService $sessions): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'target_type' => ['required', Rule::in(['customer', 'driver'])],
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'support_access' => ['nullable', 'boolean'],
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:30'],
        ]);

        $created = $sessions->create($actor, $data, $request);

        return response()->json([
            'data' => $sessions->context($created['session']),
            'preview_token' => $created['token'],
            'token_type' => 'Preview',
        ], 201);
    }

    public function resolve(Request $request, AppPreviewSessionService $sessions): JsonResponse
    {
        $token = $request->header('X-Foodex-Preview-Token');
        if (! is_string($token) || trim($token) === '') {
            $token = $request->input('preview_token');
        }

        abort_unless(is_string($token), 401, 'Preview token is required.');

        $session = $sessions->resolve($token, $request);

        return response()->json(['data' => $sessions->context($session)]);
    }

    public function destroy(
        Request $request,
        int $session,
        AppPreviewSessionService $sessions,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $model = AppPreviewSession::query()->findOrFail($session);
        $reason = $request->input('reason');
        $sessions->revoke(
            $actor,
            $model,
            $request,
            is_string($reason) ? $reason : null,
        );

        return response()->noContent();
    }
}
