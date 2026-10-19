<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\Models\RestaurantLocationProfile;
use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\RestaurantSaaS\RestaurantOperationsOverviewService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

final class SmartOrderingController extends Controller
{
    public function index(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingRevisionService $revisions,
        RestaurantOnboardingService $onboarding,
        RestaurantOperationsOverviewService $overview,
    ): View {
        $actor = $this->actor($request);
        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.view', $request);

        $stores = Store::query()->whereIn('id', $storeIds)->orderBy('name')->get();
        foreach ($stores as $store) {
            $sites->ensureForStore((int) $store->id, $actor);
        }

        $requested = $request->integer('store_id');
        if ($requested > 0 && ! in_array($requested, $storeIds, true)) {
            abort(404);
        }
        $storeId = $requested > 0 ? $requested : (int) $storeIds[0];

        $site = SmartOrderingSite::query()->where('store_id', $storeId)->firstOrFail();
        $published = $revisions->published($site);
        if ($published === null && ! SmartOrderingRevision::query()->where('site_id', $site->id)->exists()) {
            $published = $revisions->ensurePublished($site, $actor);
        }
        $draft = $revisions->draft($site);
        $selectedStore = $stores->firstWhere('id', $storeId);
        abort_unless($selectedStore instanceof Store, 404);
        $onboardingStatus = $onboarding->status($selectedStore, $actor);

        $overviewFilters = $request->validate([
            'branch' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'channel' => ['nullable', Rule::in(['dine_in', 'pickup', 'delivery'])],
        ]);
        $restaurantOverview = $overview->snapshot($site, $actor, $overviewFilters);

        $entitlements = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->id)
            ->pluck('enabled', 'module')
            ->map(fn ($enabled): bool => (bool) $enabled)
            ->all();

        return view('admin.smart-ordering', [
            'stores' => $stores,
            'site' => $site,
            'published' => $published,
            'draft' => $draft,
            'entitlements' => $entitlements,
            'modules' => (array) config('smart_ordering.entitlements', []),
            'states' => (array) config('smart_ordering.runtime_states', []),
            'supportAccess' => $actor->hasRole('SUPER_ADMIN'),
            'onboardingStatus' => $onboardingStatus,
            'canManageOnboarding' => $actor->hasPermission('smart_ordering.manage', $storeId),
            'canLaunchOnboarding' => $actor->hasPermission('smart_ordering.publish', $storeId),
            'onboardingTimezones' => DateTimeZone::listIdentifiers(),
            'restaurantOverview' => $restaurantOverview,
        ]);
    }

    public function runtime(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingSiteService $sites,
        TenantContextResolver $tenants,
        RestaurantOnboardingService $onboarding,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'runtime_state' => ['required', 'string', Rule::in((array) config('smart_ordering.runtime_states', []))],
            'master_enabled' => ['nullable', 'boolean'],
        ]);

        $store = Store::query()->findOrFail((int) $site->store_id);
        $status = $onboarding->status($store, $actor);
        $guidedSetup = ($status['profile'] ?? null) instanceof RestaurantLocationProfile;
        if ($guidedSetup
            && ! ($status['launched'] ?? false)
            && ((string) $data['runtime_state'] !== 'DISABLED' || $request->boolean('master_enabled'))) {
            throw ValidationException::withMessages([
                'runtime_state' => [__('restaurant_onboarding.validation.runtime_locked')],
            ]);
        }

        $sites->updateRuntime($actor, $site, (string) $data['runtime_state'], $request->boolean('master_enabled'), $request);

        return back()->with('status', __('smart_ordering.saved'));
    }

    public function entitlements(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingSiteService $sites,
        TenantContextResolver $tenants,
        RestaurantOnboardingService $onboarding,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $store = Store::query()->findOrFail((int) $site->store_id);
        $status = $onboarding->status($store, $actor);
        $guidedSetup = ($status['profile'] ?? null) instanceof RestaurantLocationProfile;
        $channelsStep = collect($status['setup_flow'] ?? [])->firstWhere('key', 'channels');
        if ($guidedSetup && ! ($status['launched'] ?? false) && ! (bool) ($channelsStep['available'] ?? false)) {
            throw ValidationException::withMessages([
                'modules' => [__('restaurant_onboarding.validation.step_locked')],
            ]);
        }

        $selected = array_values(array_filter((array) $request->input('modules', []), 'is_string'));
        if ($guidedSetup && ! ($status['launched'] ?? false)) {
            $selected = array_values(array_intersect(
                $selected,
                ['public_menu', 'ordering', 'dine_in', 'pickup', 'delivery', 'whatsapp', 'campaign_qr'],
            ));
        }
        $allowed = array_values(array_filter((array) config('smart_ordering.entitlements', []), 'is_string'));
        $values = [];

        foreach ($allowed as $module) {
            $values[$module] = in_array($module, $selected, true);
        }

        $sites->replaceEntitlements($site, $values, $actor, $request);

        return back()->with('status', __('smart_ordering.saved'));
    }

    public function slug(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingSiteService $sites,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['slug' => ['required', 'string', 'max:64']]);
        $sites->renameSlug($actor, $site, (string) $data['slug'], $request);

        return back()->with('status', __('smart_ordering.saved'));
    }

    public function customDomain(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingSiteService $sites,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['custom_domain' => ['nullable', 'string', 'max:255']]);
        $sites->updateCustomDomain($actor, $site, $data['custom_domain'] ?? null, $request);

        return back()->with('status', __('smart_ordering.saved'));
    }

    public function verifyCustomDomain(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingSiteService $sites,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['verification_token' => ['required', 'string', 'max:191']]);
        $sites->verifyCustomDomain($actor, $site, (string) $data['verification_token'], $request);

        return back()->with('status', __('smart_ordering.domain_verified'));
    }

    public function draft(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingRevisionService $revisions,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $revisions->createOrReuseDraft($actor, $site, $request);

        return back()->with('status', __('smart_ordering.draft_ready'));
    }

    public function updateContent(
        Request $request,
        SmartOrderingRevision $revision,
        SmartOrderingRevisionService $revisions,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $site = $revision->site()->firstOrFail();
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        abort_unless($revision->status === 'draft', 409);

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['nullable', 'string', 'max:160'],
            'tagline_en' => ['nullable', 'string', 'max:240'],
            'tagline_ar' => ['nullable', 'string', 'max:240'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_url' => ['nullable', 'url', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'about_en' => ['nullable', 'string', 'max:1600'],
            'about_ar' => ['nullable', 'string', 'max:1600'],
            'chef_note_en' => ['nullable', 'string', 'max:1600'],
            'chef_note_ar' => ['nullable', 'string', 'max:1600'],
            'location_en' => ['nullable', 'string', 'max:500'],
            'location_ar' => ['nullable', 'string', 'max:500'],
            'map_url' => ['nullable', 'url', 'max:500'],
            'reservation_cta_en' => ['nullable', 'string', 'max:160'],
            'reservation_cta_ar' => ['nullable', 'string', 'max:160'],
            'reservation_url' => ['nullable', 'url', 'max:500'],
            'allergen_note_en' => ['nullable', 'string', 'max:800'],
            'allergen_note_ar' => ['nullable', 'string', 'max:800'],
        ]);

        $payload = (array) $revision->payload;
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $identity = is_array($content['identity'] ?? null) ? $content['identity'] : [];
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];

        foreach ([
            'name_en', 'name_ar', 'tagline_en', 'tagline_ar',
            'description_en', 'description_ar', 'phone', 'whatsapp_url',
        ] as $field) {
            $identity[$field] = trim((string) ($data[$field] ?? ''));
        }

        foreach ([
            'about_en', 'about_ar', 'chef_note_en', 'chef_note_ar',
            'location_en', 'location_ar', 'map_url',
            'reservation_cta_en', 'reservation_cta_ar', 'reservation_url',
            'allergen_note_en', 'allergen_note_ar',
        ] as $field) {
            $sections[$field] = trim((string) ($data[$field] ?? ''));
        }

        foreach (['logo', 'cover'] as $asset) {
            $file = $request->file($asset);
            if ($file === null) {
                continue;
            }

            $key = $asset.'_url';
            $previous = trim((string) ($identity[$key] ?? ''));
            if ($previous !== '' && ! str_starts_with($previous, '/') && ! str_contains($previous, '://')) {
                Storage::disk('public')->delete($previous);
            }
            $identity[$key] = $file->store('smart-ordering/branding/'.$site->public_id, 'public');
        }

        $content['identity'] = $identity;
        $content['sections'] = $sections;
        $payload['content'] = $content;

        $revisions->updateDraft($actor, $revision, $payload, $request);

        return back()->with('status', __('smart_ordering.content_editor.saved'));
    }

    public function updateDraft(
        Request $request,
        SmartOrderingRevision $revision,
        SmartOrderingRevisionService $revisions,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $site = $revision->site()->firstOrFail();
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['config_json' => ['required', 'string']]);

        try {
            $payload = json_decode((string) $data['config_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['config_json' => __('smart_ordering.validation.json')]);
        }

        if (is_array($payload) === false) {
            throw ValidationException::withMessages(['config_json' => __('smart_ordering.validation.json')]);
        }

        $revisions->updateDraft($actor, $revision, $payload, $request);

        return back()->with('status', __('smart_ordering.saved'));
    }

    public function publish(
        Request $request,
        SmartOrderingRevision $revision,
        SmartOrderingMenuRevisionService $revisions,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $site = $revision->site()->firstOrFail();
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.publish');
        $revisions->publish($actor, $site, $revision, $request);

        return back()->with('status', __('smart_ordering.published'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): User {
        $actor = $this->actor($request);
        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            $permission,
            'b2c',
        );

        return $actor;
    }
}
