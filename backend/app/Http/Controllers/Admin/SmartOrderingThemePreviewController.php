<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\Themes\RestaurantMenuTemplateDemoFixture;
use App\Domain\SmartOrdering\Themes\RestaurantTemplateSelectionService;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SmartOrderingThemePreviewController extends Controller
{
    public function index(
        Request $request,
        ThemeRegistry $themes,
        SmartOrderingRevisionService $revisions,
        RestaurantTemplateSelectionService $selection,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.view', $request);

        $siteKey = trim((string) $request->query('site', ''));
        $currentTheme = null;
        $canApply = false;
        if ($siteKey !== '') {
            $site = SmartOrderingSite::query()
                ->whereIn('store_id', $storeIds)
                ->where(function ($query) use ($siteKey): void {
                    $query->where('public_id', $siteKey)->orWhere('slug', $siteKey);
                })
                ->first();
            abort_unless($site instanceof SmartOrderingSite, 404);

            $revision = $revisions->draft($site) ?? $revisions->published($site);
            $selected = $revision === null ? null : $selection->selected($revision);
            $currentTheme = $selected['id'] ?? null;
            $canApply = $actor->hasPermission('smart_ordering.manage', (int) $site->store_id);
        }

        $selectedTheme = $themes->resolveProduction($request->query('theme', $currentTheme));

        return view('admin.smart-ordering.appearance', [
            'themes' => $themes->production(),
            'siteKey' => $siteKey,
            'selectedTheme' => $selectedTheme,
            'currentTheme' => $currentTheme,
            'canApply' => $canApply,
            'selectedAppearance' => $themes->sanitizeAppearance(
                $request->query('appearance', ($themes->productionDefinition($selectedTheme)['dark'] ?? false) ? 'dark' : 'light'),
            ),
            'selectedLocale' => $themes->sanitizeLocale($request->query('lang')),
            'selectedRevision' => $request->query('revision') === 'published' ? 'published' : 'draft',
            'selectedDevice' => in_array($request->query('device'), ['mobile', 'tablet', 'desktop'], true)
                ? $request->query('device')
                : 'mobile',
        ]);
    }

    public function apply(
        Request $request,
        ThemeRegistry $themes,
        RestaurantTemplateSelectionService $selection,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.view', $request);
        $data = $request->validate([
            'site' => ['required', 'string', 'max:100'],
            'theme' => ['required', 'string', 'max:100'],
            'lang' => ['nullable', 'string'],
            'device' => ['nullable', 'string'],
        ]);

        $siteKey = trim((string) $data['site']);
        $site = SmartOrderingSite::query()
            ->whereIn('store_id', $storeIds)
            ->where(function ($query) use ($siteKey): void {
                $query->where('public_id', $siteKey)->orWhere('slug', $siteKey);
            })
            ->first();
        abort_unless($site instanceof SmartOrderingSite, 404);

        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            'smart_ordering.manage',
            'b2c',
        );
        $theme = strtolower(trim((string) $data['theme']));
        abort_unless(in_array($theme, $themes->productionIds(), true), 422);

        $selection->selectDraft($actor, $site, $theme, $request);

        return redirect()->route('admin.smart-ordering.appearance.index', array_filter([
            'site' => $siteKey,
            'theme' => $theme,
            'lang' => $themes->sanitizeLocale($data['lang'] ?? null),
            'device' => in_array(($data['device'] ?? null), ['mobile', 'tablet', 'desktop'], true)
                ? $data['device']
                : 'mobile',
        ]))->with('status', __('smart_ordering.appearance.template_applied'));
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

        $revision = $request->query('revision') === 'published' ? 'published' : 'draft';
        $snapshot = $this->provider()->revision($siteKey, $revision);
        abort_if($snapshot === null, 404);

        $prepared = $renderer->prepare(
            $snapshot,
            $themes->resolveProduction($request->query('theme')),
            $themes->sanitizeAppearance($request->query('appearance')),
            $themes->sanitizeLocale($request->query('lang')),
            null,
        );

        return $this->privatePreviewResponse($renderer, $prepared, $revision);
    }

    public function demo(
        Request $request,
        ThemeRegistry $themes,
        StorefrontThemeRenderer $renderer,
        RestaurantMenuTemplateDemoFixture $fixture,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $requestedTheme = strtolower(trim((string) $request->query('theme')));
        abort_unless(in_array($requestedTheme, $themes->productionIds(), true), 404);

        $definition = $themes->productionDefinition($requestedTheme);
        $appearance = $request->has('appearance')
            ? $themes->sanitizeAppearance($request->query('appearance'))
            : (($definition['dark'] ?? false) ? 'dark' : 'light');

        $prepared = $renderer->prepare(
            $fixture->snapshot(),
            $requestedTheme,
            $appearance,
            $themes->sanitizeLocale($request->query('lang')),
            null,
        );
        $prepared['revision'] = 'demo';

        return $this->privatePreviewResponse($renderer, $prepared, 'demo');
    }

    private function provider(): StorefrontSnapshotProvider
    {
        return app()->bound(StorefrontSnapshotProvider::class)
            ? app(StorefrontSnapshotProvider::class)
            : app(ConfigStorefrontSnapshotProvider::class);
    }

    /** @param array<string,mixed> $prepared */
    private function privatePreviewResponse(
        StorefrontThemeRenderer $renderer,
        array $prepared,
        string $previewState,
    ): Response {
        return response()->view($renderer->view($prepared), $prepared)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('X-FOODEX-Preview-State', $previewState);
    }
}
