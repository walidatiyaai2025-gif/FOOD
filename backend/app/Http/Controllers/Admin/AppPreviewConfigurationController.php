<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use App\Support\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AppPreviewConfigurationController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantResolver,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    public function __invoke(
        Request $request,
        StorefrontRevisionService $revisions,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['draft', 'published'])],
            'support_access' => ['nullable', 'boolean'],
        ]);

        $channel = (string) $data['channel'];
        $requestedStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($channel === 'b2b') {
            $storeId = $this->wholesalePrincipal->storeId();
            abort_if($requestedStoreId !== null && $requestedStoreId !== $storeId, 404);
            $this->tenantResolver->wholesale($user, $storeId);
        } else {
            abort_if($requestedStoreId === null, 422, 'Retail Guest preview configuration requires a store.');
            $storeId = $requestedStoreId;
            $this->tenantResolver->retail(
                $user,
                $storeId,
                (bool) ($data['support_access'] ?? false),
                $request,
            );
        }

        abort_unless($user->hasPermission('app_preview.view', $storeId), 403);

        $mode = (string) $data['mode'];
        $revision = $revisions->findCurrent($storeId, $channel, $mode);

        if ($revision === null) {
            return response()->json([
                'data' => null,
                'state' => $mode.'_unavailable',
                'read_only' => true,
                'persona' => 'guest',
                'mode' => $mode,
            ]);
        }

        return response()->json([
            'data' => [
                ...$revisions->metadata($revision),
                'payload' => $revision->payload,
                'read_only' => true,
                'persona' => 'guest',
                'mode' => $mode,
            ],
        ]);
    }
}
