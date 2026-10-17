<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Http\Controllers\Controller;
use App\Models\SmartOrderingQrCode;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Services\SmartOrderingAttributionService;
use App\Services\SmartOrderingQrAssetService;
use App\Services\SmartOrderingQrManager;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class SmartOrderingQrController extends Controller
{
    public function index(
        Request $request,
        OperationalTenantScope $scope,
        SmartOrderingAttributionService $attribution,
        SmartOrderingQrManager $manager,
    ): View {
        $actor = $this->actor($request);
        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.qr.view', $request);

        $canCreate = collect($storeIds)
            ->contains(fn (int $storeId): bool => $actor->hasPermission('smart_ordering.qr.manage', $storeId));

        $filters = $request->validate([
            'store_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(SmartOrderingQrManager::TYPES)],
            'state' => ['nullable', Rule::in(['enabled', 'disabled', 'scheduled', 'expired'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $selectedStoreId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        if ($selectedStoreId !== null && ! in_array($selectedStoreId, $storeIds, true)) {
            abort(404);
        }

        $query = SmartOrderingQrCode::query()
            ->with(['store:id,name,code,is_active', 'promotion:id,name,store_id', 'activeToken'])
            ->whereIn('store_id', $storeIds)
            ->when($selectedStoreId !== null, fn ($q) => $q->where('store_id', $selectedStoreId))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['q']), function ($q) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
                $q->where(function ($nested) use ($term): void {
                    $nested->where('label', 'like', $term)
                        ->orWhere('campaign', 'like', $term)
                        ->orWhere('source', 'like', $term)
                        ->orWhere('branch_reference', 'like', $term)
                        ->orWhere('table_reference', 'like', $term);
                });
            })
            ->when(($filters['state'] ?? null) === 'enabled', fn ($q) => $q
                ->where('is_enabled', true)
                ->where(fn ($dates) => $dates->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('valid_until')->orWhere('valid_until', '>', now())))
            ->when(($filters['state'] ?? null) === 'disabled', fn ($q) => $q->where('is_enabled', false))
            ->when(($filters['state'] ?? null) === 'scheduled', fn ($q) => $q->where('valid_from', '>', now()))
            ->when(($filters['state'] ?? null) === 'expired', fn ($q) => $q->where('valid_until', '<=', now()))
            ->orderByDesc('id');

        $qrs = $query->paginate(25, ['*'], 'qr_page')->withQueryString();
        $metrics = $attribution->metricsForQrIds(
            $qrs->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $qrs->getCollection()->each(function (SmartOrderingQrCode $qr) use ($manager): void {
            $qr->setAttribute('public_url', $manager->publicUrlFor($qr));
        });

        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $promotions = DB::table('promotions')
            ->leftJoin('stores', 'stores.id', '=', 'promotions.store_id')
            ->where('promotions.is_active', true)
            ->where(function ($q) use ($storeIds): void {
                $q->whereIn('promotions.store_id', $storeIds)->orWhereNull('promotions.store_id');
            })
            ->orderBy('promotions.name')
            ->limit(500)
            ->get(['promotions.id', 'promotions.store_id', 'promotions.name', 'stores.name as store_name']);

        return view('admin.smart-ordering-qr', [
            'user' => $actor,
            'qrs' => $qrs,
            'metrics' => $metrics,
            'stores' => $stores,
            'promotions' => $promotions,
            'types' => SmartOrderingQrManager::TYPES,
            'filters' => $filters,
            'canCreate' => $canCreate,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'smart_ordering',
        ]);
    }

    public function store(
        Request $request,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $this->payload($request, true);
        app(RestaurantSaasStoreAccess::class)->assertStore($actor, (int) $data['store_id'], 'smart_ordering.qr.manage', 'b2c');

        $created = $manager->create($data, (int) $actor->getKey());
        $audit->record('smart_ordering.qr.created', $actor, $created['qr'], null, $created['qr']->toArray(), $request);

        return back()->with('status', __('smart_ordering_qr.flash.created'));
    }

    public function update(
        Request $request,
        SmartOrderingQrCode $qr,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        app(RestaurantSaasStoreAccess::class)->assertStore($actor, (int) $qr->store_id, 'smart_ordering.qr.manage', 'b2c');
        $before = $qr->toArray();
        $updated = $manager->update($qr, $this->payload($request, false), (int) $actor->getKey());
        $audit->record('smart_ordering.qr.updated', $actor, $updated, $before, $updated->toArray(), $request);

        return back()->with('status', __('smart_ordering_qr.flash.updated'));
    }

    public function toggle(
        Request $request,
        SmartOrderingQrCode $qr,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        app(RestaurantSaasStoreAccess::class)->assertStore($actor, (int) $qr->store_id, 'smart_ordering.qr.manage', 'b2c');
        $before = $qr->toArray();
        $updated = $manager->setEnabled($qr, ! $qr->is_enabled, (int) $actor->getKey());
        $audit->record('smart_ordering.qr.toggled', $actor, $updated, $before, $updated->toArray(), $request);

        return back()->with('status', __('smart_ordering_qr.flash.toggled'));
    }

    public function rotate(
        Request $request,
        SmartOrderingQrCode $qr,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        app(RestaurantSaasStoreAccess::class)->assertStore($actor, (int) $qr->store_id, 'smart_ordering.qr.manage', 'b2c');

        $data = $request->validate([
            'keep_previous' => ['nullable', 'boolean'],
            'legacy_expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $keepPrevious = (bool) ($data['keep_previous'] ?? true);

        $manager->rotate(
            $qr,
            (int) $actor->getKey(),
            $keepPrevious,
            isset($data['legacy_expires_at']) ? Carbon::parse((string) $data['legacy_expires_at']) : null,
        );

        $audit->record('smart_ordering.qr.token_rotated', $actor, $qr, null, [
            'store_id' => (int) $qr->store_id,
            'keep_previous' => $keepPrevious,
            'legacy_expires_at' => $data['legacy_expires_at'] ?? null,
        ], $request);

        return back()->with('status', __('smart_ordering_qr.flash.rotated'));
    }

    public function svg(
        Request $request,
        SmartOrderingQrCode $qr,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        SmartOrderingQrAssetService $assets,
    ): Response {
        $this->authorizeView($request, $qr, $scope);
        $asset = $manager->printAssetContext($qr, $this->assetVersion($request));
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($assets->svg($asset['url']), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="foodex-qr-'.$qr->public_id.'.svg"',
            'Content-Location' => route('admin.smart-ordering.qr.svg', [
                'qr' => $qr,
                'version' => $asset['version'],
            ], true),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-FOODEX-QR-Asset-Version' => $asset['version'],
            'X-FOODEX-QR-Asset-State' => $asset['state'],
        ]);
    }

    public function pdf(
        Request $request,
        SmartOrderingQrCode $qr,
        OperationalTenantScope $scope,
        SmartOrderingQrManager $manager,
        SmartOrderingQrAssetService $assets,
    ): Response {
        $this->authorizeView($request, $qr, $scope);
        $asset = $manager->printAssetContext($qr, $this->assetVersion($request));

        return response($assets->pdf($asset['url'], (string) $qr->label), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="foodex-qr-'.$qr->public_id.'.pdf"',
            'Content-Location' => route('admin.smart-ordering.qr.pdf', [
                'qr' => $qr,
                'version' => $asset['version'],
            ], true),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-FOODEX-QR-Asset-Version' => $asset['version'],
            'X-FOODEX-QR-Asset-State' => $asset['state'],
        ]);
    }

    private function authorizeView(Request $request, SmartOrderingQrCode $qr, OperationalTenantScope $scope): void
    {
        $actor = $this->actor($request);
        app(RestaurantSaasStoreAccess::class)->assertStore($actor, (int) $qr->store_id, 'smart_ordering.qr.view', 'b2c');
    }

    private function assetVersion(Request $request): ?string
    {
        if (! $request->query->has('version')) {
            return null;
        }

        $version = $request->query('version');
        abort_unless(is_string($version) && $version !== '', 404);

        return strtolower($version);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $creating): array
    {
        $rules = [
            'type' => ['required', Rule::in(SmartOrderingQrManager::TYPES)],
            'label' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'target_path' => ['required', 'string', 'max:1024'],
            'source' => ['nullable', 'string', 'max:64'],
            'medium' => ['nullable', 'string', 'max:64'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'branch_reference' => ['nullable', 'string', 'max:120'],
            'table_reference' => ['nullable', 'string', 'max:120'],
            'promotion_id' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ];

        if ($creating) {
            $rules['store_id'] = ['required', 'integer', 'min:1'];
            $rules['is_enabled'] = ['nullable', 'boolean'];
        }

        return $request->validate($rules);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
