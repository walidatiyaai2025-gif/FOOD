<?php

namespace App\Http\Controllers;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
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
    public function __invoke(
        Request $request,
        string $siteKey,
        StorefrontThemeRenderer $renderer,
        SmartOrderingRevisionService $revisions,
    ): Response {
        $analyticsSite = null;
        $published = null;
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

            if ($analyticsSite instanceof SmartOrderingSite) {
                $published = $revisions->published($analyticsSite);
                abort_if($published === null, 404);
            }
        }

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

        $attribution = app(SmartOrderingAttributionService::class);
        $attributionContext = $attribution->contextFromRequest($request);
        $trustedAttribution = $attributionContext !== null && $analyticsSite instanceof SmartOrderingSite
            ? $attribution->serverContext($attributionContext, (int) $analyticsSite->store_id)
            : null;
        $prepared['analytics'] = [
            'site_public_id' => $analyticsSite?->public_id,
            'branch_public_id' => $analyticsBranch?->public_id,
            'attribution_context' => $attributionContext,
        ];
        $prepared['ordering'] = [
            'enabled' => $analyticsSite instanceof SmartOrderingSite
                && $analyticsBranch !== null
                && $published !== null
                && (bool) ($prepared['is_open'] ?? false),
            'site_slug' => $analyticsSite?->slug,
            'branch_public_id' => $analyticsBranch?->public_id,
            'revision_public_id' => $published?->public_id,
            'attribution_context' => $attributionContext,
            'supports_pickup' => $analyticsBranch !== null && (bool) $analyticsBranch->supports_pickup,
            'supports_delivery' => $analyticsBranch !== null && (bool) $analyticsBranch->supports_delivery,
            'supports_dine_in' => $analyticsBranch !== null
                && (bool) $analyticsBranch->supports_dine_in
                && ($trustedAttribution['type'] ?? null) === 'table'
                && trim((string) ($trustedAttribution['table_reference'] ?? '')) !== '',
        ];

        $cacheControl = $attributionContext === null
            ? 'public, max-age=60, stale-while-revalidate=300'
            : 'private, no-store';

        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', $cacheControl)
            ->header('Vary', 'Accept-Language')
            ->header('X-Robots-Tag', 'index, follow, max-image-preview:large');
    }
}
