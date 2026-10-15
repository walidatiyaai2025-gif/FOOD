<?php

namespace App\Http\Controllers\Admin;

use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class SmartOrderingThemePreviewController extends Controller
{
    public function index(Request $request, ThemeRegistry $themes): View
    {
        return view('admin.smart-ordering.appearance', [
            'themes' => $themes->all(),
            'siteKey' => trim((string) $request->query('site', '')),
            'selectedTheme' => $themes->resolve($request->query('theme')),
            'selectedAppearance' => $themes->sanitizeAppearance($request->query('appearance')),
            'selectedLocale' => $themes->sanitizeLocale($request->query('lang')),
            'selectedRevision' => $request->query('revision') === 'published' ? 'published' : 'draft',
            'selectedDevice' => in_array($request->query('device'), ['mobile', 'tablet', 'desktop'], true) ? $request->query('device') : 'mobile',
        ]);
    }

    public function preview(Request $request, ThemeRegistry $themes, StorefrontThemeRenderer $renderer): Response
    {
        $siteKey = trim((string) $request->query('site', ''));
        abort_if($siteKey === '', 404);

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
