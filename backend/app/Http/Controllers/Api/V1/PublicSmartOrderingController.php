<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicSmartOrderingController extends Controller
{
    public function show(
        Request $request,
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingRevisionService $revisions,
    ): JsonResponse {
        $site = $runtime->resolveBySlug($slug);
        abort_if($site === null, 404);
        $runtime->assertRequest($site, Module::PUBLIC_MENU, $request);

        $published = $revisions->published($site);
        abort_if($published === null, 404);

        return $this->response($site, $published, $runtime);
    }

    public function context(Request $request, SmartOrderingRuntimePolicy $runtime): JsonResponse
    {
        $site = $request->attributes->get('smart_ordering.site');
        $branch = $request->attributes->get('smart_ordering.branch');
        $revision = $request->attributes->get('smart_ordering.revision');

        abort_unless($site instanceof SmartOrderingSite, 404);
        abort_unless($revision instanceof SmartOrderingRevision, 404);
        abort_unless($branch === null || $branch instanceof SmartOrderingBranch, 404);

        return $this->response($site, $revision, $runtime, $branch);
    }

    private function response(
        SmartOrderingSite $site,
        SmartOrderingRevision $published,
        SmartOrderingRuntimePolicy $runtime,
        ?SmartOrderingBranch $selectedBranch = null,
    ): JsonResponse {
        $branches = $site->branches()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name_en')
            ->get()
            ->map(fn (SmartOrderingBranch $branch): array => [
                'id' => (string) $branch->public_id,
                'name_ar' => $branch->name_ar,
                'name_en' => (string) $branch->name_en,
                'timezone' => (string) $branch->timezone,
                'is_default' => (bool) $branch->is_default,
            ])->values();

        return response()->json([
            'site' => [
                'id' => (string) $site->public_id,
                'slug' => (string) $site->slug,
                'runtime_state' => $runtime->effectiveState($site)->value,
                'configuration' => (array) $published->payload,
                'published_revision' => [
                    'id' => (string) $published->public_id,
                    'checksum' => (string) $published->checksum,
                    'published_at' => $published->published_at?->toISOString(),
                ],
            ],
            'selected_branch' => $selectedBranch === null ? null : [
                'id' => (string) $selectedBranch->public_id,
                'name_ar' => $selectedBranch->name_ar,
                'name_en' => (string) $selectedBranch->name_en,
                'timezone' => (string) $selectedBranch->timezone,
            ],
            'branches' => $branches,
        ]);
    }
}
