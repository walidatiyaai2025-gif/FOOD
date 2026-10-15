<?php

namespace App\Http\Controllers;

use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
        $canonical = $request->url();

        $cacheKey = 'smart-ordering:storefront:'.hash('sha256', implode('|', [
            $siteKey,
            $revision,
            (string) $locale,
            $request->getSchemeAndHttpHost(),
            $request->path(),
        ]));

        $prepared = Cache::remember(
            $cacheKey,
            now()->addMinute(),
            fn (): array => $renderer->prepare($snapshot, null, null, $locale, $canonical),
        );

        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
            ->header('Vary', 'Accept-Language');
    }
}
