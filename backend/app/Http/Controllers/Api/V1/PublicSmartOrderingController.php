<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PublicSmartOrderingController extends Controller
{
    public function show(
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingRevisionService $revisions,
    ): JsonResponse {
        $site = $runtime->resolveBySlug($slug);
        abort_if($site === null, 404);
        $runtime->assertPublic($site, 'public_menu');

        $published = $revisions->published($site);
        abort_if($published === null, 404);

        $branches = $site->branches()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name_en')
            ->get()
            ->map(fn ($branch): array => [
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
                'configuration' => $published->payload,
            ],
            'branches' => $branches,
        ]);
    }
}
