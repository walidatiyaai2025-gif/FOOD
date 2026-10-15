<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SmartOrdering\SmartOrderingMenuReadService;
use App\Domain\SmartOrdering\SmartOrderingModifierService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingMenuController extends Controller
{
    public function menu(
        Request $request,
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
    ): JsonResponse {
        [$site, $branch, $locale] = $this->context($request, $slug, $runtime, $menus);

        return response()->json(['data' => $menus->menu($site, $locale, $branch)]);
    }

    public function categories(
        Request $request,
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
    ): JsonResponse {
        [$site, $branch, $locale] = $this->context($request, $slug, $runtime, $menus);

        return response()->json(['data' => $menus->categories($site, $locale, $branch)]);
    }

    public function items(
        Request $request,
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
    ): JsonResponse {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'uuid'],
            'featured' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'branch' => ['nullable', 'uuid'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        [$site, $branch, $locale] = $this->context($request, $slug, $runtime, $menus);

        $result = $menus->searchItems(
            $site,
            $locale,
            $branch,
            isset($data['q']) ? (string) $data['q'] : null,
            isset($data['category']) ? (string) $data['category'] : null,
            array_key_exists('featured', $data) ? (bool) $data['featured'] : null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
        );

        return response()->json($result);
    }

    public function item(
        Request $request,
        string $slug,
        string $item,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
    ): JsonResponse {
        [$site, $branch, $locale] = $this->context($request, $slug, $runtime, $menus);

        return response()->json(['data' => $menus->itemDetail($site, $item, $locale, $branch)]);
    }

    public function quote(
        Request $request,
        string $slug,
        string $item,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
        SmartOrderingModifierService $modifiers,
    ): JsonResponse {
        $data = $request->validate([
            'branch' => ['nullable', 'uuid'],
            'selections' => ['nullable', 'array'],
            'selections.*' => ['array'],
            'selections.*.*' => ['uuid'],
        ]);

        [$site, $branch] = $this->context($request, $slug, $runtime, $menus);
        $revision = $menus->publishedRevision($site);
        $model = \App\Domain\SmartOrdering\Models\SmartOrderingMenuItem::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->where('public_id', $item)
            ->firstOrFail();

        /** @var array<string,list<string>> $selections */
        $selections = (array) ($data['selections'] ?? []);

        return response()->json([
            'data' => $modifiers->quote($site, $model, $selections, $branch),
        ]);
    }

    /**
     * @return array{
     *   0:\App\Domain\SmartOrdering\Models\SmartOrderingSite,
     *   1:?\App\Domain\SmartOrdering\Models\SmartOrderingBranch,
     *   2:string
     * }
     */
    private function context(
        Request $request,
        string $slug,
        SmartOrderingRuntimePolicy $runtime,
        SmartOrderingMenuReadService $menus,
    ): array {
        $site = $runtime->resolveBySlug($slug);
        abort_if($site === null, 404);
        $runtime->assertRequest($site, 'public_menu', $request);

        $branchReference = $request->query('branch');
        $branch = $menus->branch($site, is_string($branchReference) ? $branchReference : null);
        if ($branch !== null) {
            $runtime->assertRequest($site, 'public_menu', $request, $branch);
        }

        $locale = strtolower((string) $request->query('locale', 'en'));
        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'en';

        return [$site, $branch, $locale];
    }
}
