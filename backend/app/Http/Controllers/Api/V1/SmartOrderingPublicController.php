<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Http\Controllers\Controller;
use App\Models\SmartOrderingBranchScope;
use App\Models\SmartOrderingRevision;
use App\Models\SmartOrderingSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SmartOrderingPublicController extends Controller
{
    public function context(Request $request, SmartOrderingRuntimePolicy $runtime): JsonResponse
    {
        $site = $request->attributes->get('smart_ordering.site');
        $branch = $request->attributes->get('smart_ordering.branch');
        $revision = $request->attributes->get('smart_ordering.revision');

        abort_unless($site instanceof SmartOrderingSite, 404);
        abort_unless($revision instanceof SmartOrderingRevision, 404);
        abort_unless($branch === null || $branch instanceof SmartOrderingBranchScope, 404);

        return response()->json([
            'data' => [
                'site' => [
                    'id' => (string) $site->public_id,
                    'slug' => (string) $site->slug,
                ],
                'branch' => $branch === null ? null : [
                    'id' => (string) $branch->public_id,
                    'code' => (string) $branch->code,
                    'name' => (string) $branch->name,
                ],
                'runtime_state' => $runtime->effectiveState($site)->value,
                'revision' => [
                    'id' => (string) $revision->public_id,
                    'checksum' => (string) $revision->checksum,
                    'published_at' => $revision->published_at?->toISOString(),
                    'config' => (array) $revision->payload,
                ],
            ],
        ]);
    }
}
