<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StorefrontRevision;
use App\Models\User;
use App\Services\AppPreviewSessionService;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use App\Support\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StorefrontRevisionController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantResolver,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    public function index(Request $request, StorefrontRevisionService $revisions): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'support_access' => ['nullable', 'boolean'],
        ]);

        $storeId = $this->authorizeScope(
            $actor,
            (string) $data['channel'],
            isset($data['store_id']) ? (int) $data['store_id'] : null,
            $request->boolean('support_access'),
            $request,
            false,
        );

        $query = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $data['channel'])
            ->latest('id');

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'data' => $query->limit(100)->get()
                ->map(fn (StorefrontRevision $revision): array => $revisions->metadata($revision))
                ->values(),
            'schema_version' => StorefrontRevisionService::SCHEMA_VERSION,
        ]);
    }

    public function draft(Request $request, StorefrontRevisionService $revisions): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'support_access' => ['nullable', 'boolean'],
        ]);

        $storeId = $this->authorizeScope(
            $actor,
            (string) $data['channel'],
            isset($data['store_id']) ? (int) $data['store_id'] : null,
            $request->boolean('support_access'),
            $request,
            true,
        );

        $revision = $revisions->createOrReuseDraft(
            $actor,
            $storeId,
            (string) $data['channel'],
            $request,
        );

        return response()->json([
            'data' => [
                ...$revisions->metadata($revision),
                'payload' => $revision->payload,
            ],
        ], 201);
    }

    public function show(
        Request $request,
        string $revision,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $actor = $this->actor($request);
        $model = $this->revision($revision);

        $this->authorizeScope(
            $actor,
            (string) $model->channel,
            (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
            false,
        );

        return response()->json([
            'data' => [
                ...$revisions->metadata($model),
                'payload' => $model->payload,
            ],
        ]);
    }

    public function update(
        Request $request,
        string $revision,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $actor = $this->actor($request);
        $model = $this->revision($revision);

        $this->authorizeScope(
            $actor,
            (string) $model->channel,
            (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
            true,
        );

        $data = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $updated = $revisions->updateDraft($actor, $model, (array) $data['payload'], $request);

        return response()->json([
            'data' => [
                ...$revisions->metadata($updated),
                'payload' => $updated->payload,
            ],
        ]);
    }

    public function publish(
        Request $request,
        string $revision,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $actor = $this->actor($request);
        $model = $this->revision($revision);

        $this->authorizeScope(
            $actor,
            (string) $model->channel,
            (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
            true,
        );

        $published = $revisions->publish($actor, $model, $request);

        return response()->json([
            'data' => [
                ...$revisions->metadata($published),
                'payload' => $published->payload,
            ],
        ]);
    }

    public function rollback(
        Request $request,
        string $revision,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $actor = $this->actor($request);
        $source = $this->revision($revision);

        $this->authorizeScope(
            $actor,
            (string) $source->channel,
            (int) $source->store_id,
            $request->boolean('support_access'),
            $request,
            true,
        );

        $rolledBack = $revisions->rollback($actor, $source, $request);

        return response()->json([
            'data' => [
                ...$revisions->metadata($rolledBack),
                'payload' => $rolledBack->payload,
            ],
        ]);
    }

    public function resolveCurrentPreviewConfiguration(
        Request $request,
        AppPreviewSessionService $sessions,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $token = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($token) && trim($token) !== '',
            401,
            'Preview token is required.',
        );

        $data = $request->validate([
            'mode' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $session = $sessions->resolve($token, $request);
        $mode = (string) $data['mode'];
        $current = $revisions->findCurrent(
            (int) $session->store_id,
            (string) $session->channel,
            $mode,
        );

        if ($current === null) {
            return response()->json([
                'data' => null,
                'state' => $mode.'_unavailable',
                'preview_session_id' => (string) $session->public_id,
                'read_only' => true,
                'mode' => $mode,
            ]);
        }

        $resolved = $revisions->resolveForPreview($session, $current);

        return response()->json([
            'data' => [
                ...$revisions->metadata($resolved),
                'payload' => $resolved->payload,
                'preview_session_id' => (string) $session->public_id,
                'read_only' => true,
                'mode' => $mode,
            ],
        ]);
    }

    public function resolvePreview(
        Request $request,
        string $revision,
        AppPreviewSessionService $sessions,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $token = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($token) && trim($token) !== '',
            401,
            'Preview token is required.',
        );

        $session = $sessions->resolve($token, $request);
        $model = $this->revision($revision);
        $resolved = $revisions->resolveForPreview($session, $model);

        return response()->json([
            'data' => [
                ...$revisions->metadata($resolved),
                'payload' => $resolved->payload,
                'preview_session_id' => (string) $session->public_id,
                'read_only' => true,
            ],
        ]);
    }

    private function authorizeScope(
        User $actor,
        string $channel,
        ?int $requestedStoreId,
        bool $supportAccess,
        Request $request,
        bool $mutation,
    ): int {
        if ($channel === 'b2b') {
            $storeId = $this->wholesalePrincipal->storeId();
            abort_if($requestedStoreId !== null && $requestedStoreId !== $storeId, 404);
            $this->tenantResolver->wholesale($actor, $storeId);
        } else {
            abort_if($requestedStoreId === null, 422, 'Retail storefront revision requires a store.');
            $storeId = $requestedStoreId;
            $this->tenantResolver->retail($actor, $storeId, $supportAccess, $request);
        }

        abort_unless($actor->hasPermission('app_preview.view', $storeId), 403);
        if ($mutation) {
            abort_unless($actor->hasPermission('app_preview.publish', $storeId), 403);
        }

        return $storeId;
    }

    private function revision(string $publicId): StorefrontRevision
    {
        return StorefrontRevision::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
