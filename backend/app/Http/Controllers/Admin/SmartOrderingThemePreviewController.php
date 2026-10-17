<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SmartOrderingThemePreviewController extends Controller
{
    public function index(Request $request, ThemeRegistry $themes): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.view', $request);
        $siteKey = trim((string) $request->query('site', ''));
        if ($siteKey !== '') {
            abort_unless(
                DB::table('smart_ordering_sites')
                    ->whereIn('store_id', $storeIds)
                    ->where(function ($query) use ($siteKey): void {
                        $query->where('public_id', $siteKey)->orWhere('slug', $siteKey);
                    })
                    ->exists(),
                404,
            );
        }

        return view('admin.smart-ordering.appearance', [
            'themes' => $themes->all(),
            'siteKey' => $siteKey,
            'selectedTheme' => $themes->resolve($request->query('theme')),
            'selectedAppearance' => $themes->sanitizeAppearance($request->query('appearance')),
            'selectedLocale' => $themes->sanitizeLocale($request->query('lang')),
            'selectedRevision' => $request->query('revision') === 'published' ? 'published' : 'draft',
            'selectedDevice' => in_array($request->query('device'), ['mobile', 'tablet', 'desktop'], true) ? $request->query('device') : 'mobile',
        ]);
    }

    public function preview(Request $request, ThemeRegistry $themes, StorefrontThemeRenderer $renderer): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $siteKey = trim((string) $request->query('site', ''));
        abort_if($siteKey === '', 404);
        $storeId = DB::table('smart_ordering_sites')
            ->where(function ($query) use ($siteKey): void {
                $query->where('public_id', $siteKey)->orWhere('slug', $siteKey);
            })
            ->value('store_id');
        abort_unless(is_numeric($storeId), 404);
        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $storeId,
            'smart_ordering.view',
            'b2c',
        );

        $provider = app()->bound(StorefrontSnapshotProvider::class)
            ? app(StorefrontSnapshotProvider::class)
            : app(ConfigStorefrontSnapshotProvider::class);

        $revision = $request->query('revision') === 'published' ? 'published' : 'draft';
        $snapshot = $provider->revision($siteKey, $revision);
        abort_if($snapshot === null, 404);

        $prepared = $renderer->prepare(
            $snapshot,
            $themes->resolve($request->query('theme')),
            $themes->sanitizeAppearance($request->query('appearance')),
            $themes->sanitizeLocale($request->query('lang')),
            null,
        );

        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
