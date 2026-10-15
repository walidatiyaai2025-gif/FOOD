<?php

namespace App\Http\Controllers;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Services\SmartOrderingAttributionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class SmartOrderingStorefrontController extends Controller
{
    public function __invoke(Request $request, string $siteKey, StorefrontThemeRenderer $renderer): Response
    {
        $provider = app()->bound(StorefrontSnapshotProvider::class)
            ? app(StorefrontSnapshotProvider::class)
            : app(ConfigStorefrontSnapshotProvider::class);

        $snapshot = $provider->published($siteKey);
        abort_if($snapshot === null, 404);

        $locale = $request->query('lang');
        $locale = is_string($locale) ? $locale : null;
        $revision = trim((string) ($snapshot['revision'] ?? 'published'));
        $appearanceFingerprint = hash('sha256', serialize($snapshot['appearance'] ?? []));
        $canonical = $request->url();

        $cacheKey = 'smart-ordering:storefront:'.hash('sha256', implode('|', [
            $siteKey,
            $revision,
            $appearanceFingerprint,
            (string) $locale,
            $request->getSchemeAndHttpHost(),
            $request->path(),
        ]));

        $prepared = Cache::remember(
            $cacheKey,
            now()->addMinute(),
            fn (): array => $renderer->prepare($snapshot, null, null, $locale, $canonical),
        );

        $analyticsSite = null;
        if (Schema::hasTable('smart_ordering_sites')) {
            $analyticsSite = SmartOrderingSite::query()
                ->where('master_enabled', true)
                ->where(function ($query) use ($siteKey): void {
                    $query->where('slug', $siteKey);
                    if (preg_match('/^[0-9a-fA-F-]{36}$/', $siteKey) === 1) {
                        $query->orWhere('public_id', $siteKey);
                    }
                })
                ->first();
        }

        $analyticsBranch = null;
        if ($analyticsSite instanceof SmartOrderingSite) {
            $requestedBranch = trim((string) $request->query('branch', ''));
            $analyticsBranch = $analyticsSite->branches()
                ->where('is_active', true)
                ->when($requestedBranch !== '', fn ($query) => $query->where('public_id', $requestedBranch))
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($analyticsBranch === null && $requestedBranch !== '') {
                $analyticsBranch = $analyticsSite->branches()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->first();
            }
        }

        $attributionContext = app(SmartOrderingAttributionService::class)->contextFromRequest($request);
        $prepared['analytics'] = [
            'site_public_id' => $analyticsSite?->public_id,
            'branch_public_id' => $analyticsBranch?->public_id,
            'attribution_context' => $attributionContext,
        ];
        $prepared['commerce'] = [
            'branch_public_id' => $analyticsBranch?->public_id,
            'revision_public_id' => preg_match('/^[0-9a-fA-F-]{36}$/', (string) ($prepared['revision'] ?? '')) === 1
                ? (string) $prepared['revision']
                : null,
            'fulfillment_modes' => $analyticsBranch === null ? [] : array_values(array_filter([
                $analyticsBranch->supports_pickup ? 'pickup' : null,
                $analyticsBranch->supports_delivery ? 'delivery' : null,
                $analyticsBranch->supports_dine_in ? 'dine_in' : null,
            ])),
        ];

        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
            ->header('Vary', 'Accept-Language');
    }
}
