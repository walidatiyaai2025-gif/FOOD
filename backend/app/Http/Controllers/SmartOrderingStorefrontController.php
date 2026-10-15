<?php

namespace App\Http\Controllers;

use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        $prepared = $renderer->prepare($snapshot, null, null, is_string($locale) ? $locale : null, $request->url());

        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
            ->header('Vary', 'Accept-Language');
    }
}
