<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\SmartOrdering\SupplyIntelligenceService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SmartOrderingSupplyController extends Controller
{
    public function entry(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, 'smart_ordering.view', $request);

        $requested = $request->integer('store_id');
        if ($requested > 0 && ! in_array($requested, $storeIds, true)) {
            abort(404);
        }
        $storeId = in_array($requested, $storeIds, true)
            ? $requested
            : (int) (DB::table('smart_ordering_sites as sites')
                ->join('smart_ordering_entitlements as entitlements', 'entitlements.site_id', '=', 'sites.id')
                ->whereIn('sites.store_id', $storeIds)
                ->where('entitlements.scope_key', 'site')
                ->where('entitlements.module', 'foodex_supply_intelligence')
                ->where('entitlements.enabled', true)
                ->orderBy('sites.store_id')
                ->value('sites.store_id') ?? $storeIds[0]);
        $site = $sites->ensureForStore($storeId, $actor, $request);
        $this->assertEntitled($site);

        $query = array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'branch_id' => $request->query('branch_id'),
            'q' => $request->query('q'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access')) {
            $query['support_access'] = 1;
        }

        return redirect()->route('admin.smart-ordering.supply.index', ['site' => $site->getKey(), ...$query]);
    }

    public function index(
        Request $request,
        SmartOrderingSite $site,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): View {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $this->assertEntitled($site);

        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId !== null) {
            abort_unless(
                DB::table('smart_ordering_branches')
                    ->where('id', $branchId)
                    ->where('site_id', $site->getKey())
                    ->exists(),
                404,
            );
        }

        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->toString(), 'Asia/Kuwait')->startOfDay()
            : CarbonImmutable::now('Asia/Kuwait')->subDays(27)->startOfDay();
        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->toString(), 'Asia/Kuwait')->endOfDay()
            : CarbonImmutable::now('Asia/Kuwait')->endOfDay();

        return view('admin.smart-ordering-supply', [
            'site' => $site,
            'branches' => DB::table('smart_ordering_branches')
                ->where('site_id', $site->getKey())
                ->orderByDesc('is_default')
                ->orderBy('name_en')
                ->get(),
            'branchId' => $branchId,
            'from' => $from,
            'to' => $to,
            'menuItems' => $supply->menuItems($site),
            'foodexProducts' => $supply->foodexProducts($request->string('q')->toString()),
            'restaurantProducts' => $supply->restaurantProducts($site),
            'mappings' => $supply->mappings($site),
            'consumption' => $supply->consumption($site, $from, $to, $branchId),
            'recommendations' => $supply->recommendations($site, $branchId, $from, $to),
            'recipeCosts' => $supply->recipeCosts($site, $branchId),
            'stockHistory' => $supply->stockHistory($site, $branchId),
            'wasteEvents' => $supply->wasteEvents($site, $branchId),
            'stockVariance' => $supply->stockVariance($site, $from, $to, $branchId),
            'commercial' => $supply->commercialInsight($site),
            'relatedProducts' => $supply->relatedProducts($site),
            'supportAccess' => (bool) ($request->user()?->hasRole('SUPER_ADMIN') && $request->boolean('support_access')),
        ]);
    }

    public function storeMapping(
        Request $request,
        SmartOrderingSite $site,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $data = $request->validate([
            'menu_item_public_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'variant_product_id' => ['nullable', 'integer', 'min:1'],
            'foodex_product_id' => ['required', 'integer', 'min:1'],
            'quantity_per_item' => ['required', 'numeric', 'gt:0'],
            'waste_factor' => ['nullable', 'numeric', 'between:0,1'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        $supply->createMapping($actor, $site, $data);

        return back()->with('status', $this->msg('تم حفظ ربط مكوّن FOODEX.', 'FOODEX supply mapping saved.'));
    }

    public function deactivateMapping(
        Request $request,
        SmartOrderingSite $site,
        string $mapping,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $supply->deactivateMapping($actor, $site, $mapping);

        return back()->with('status', $this->msg('تم إنهاء صلاحية الربط مع الاحتفاظ بالتاريخ.', 'Mapping ended; historical consumption remains versioned.'));
    }

    public function stock(
        Request $request,
        SmartOrderingSite $site,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $data = $request->validate([
            'foodex_product_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'on_hand_quantity' => ['required', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'max:120'],
            'counted_at' => ['required', 'date'],
        ]);

        $supply->setAuthoritativeStock(
            $actor,
            $site,
            (int) $data['foodex_product_id'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (float) $data['on_hand_quantity'],
            (string) $data['source'],
            CarbonImmutable::parse((string) $data['counted_at'], 'Asia/Kuwait'),
        );

        return back()->with('status', $this->msg('تم حفظ الرصيد الموثق.', 'Authoritative stock snapshot saved.'));
    }

    public function waste(
        Request $request,
        SmartOrderingSite $site,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $data = $request->validate([
            'foodex_product_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:160'],
            'occurred_at' => ['required', 'date'],
        ]);

        $supply->recordWaste(
            $actor,
            $site,
            (int) $data['foodex_product_id'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (float) $data['quantity'],
            (string) $data['reason'],
            CarbonImmutable::parse((string) $data['occurred_at'], 'Asia/Kuwait'),
        );

        return back()->with('status', $this->msg('تم تسجيل الهدر في سجل التدقيق دون تعديل رصيد المخزون تلقائياً.', 'Waste recorded in the audit ledger without silently changing physical stock.'));
    }

    public function draft(
        Request $request,
        SmartOrderingSite $site,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $supply->createDraft(
            $actor,
            $site,
            (array) $data['quantities'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        return back()
            ->with('status', $this->msg(
                'تم تحديث سلة FOODEX كمسودة فقط — لم يتم إرسال أي طلب شراء.',
                'FOODEX B2B basket updated as a draft only — no purchase was submitted.',
            ))
            ->with('smart_ordering_supply_draft', $result);
    }

    public function dismiss(
        Request $request,
        SmartOrderingSite $site,
        int $product,
        SupplyIntelligenceService $supply,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $this->assertEntitled($site);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'integer', 'between:1,90'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $supply->dismiss(
            $actor,
            $site,
            $product,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (int) ($data['days'] ?? 7),
            $data['reason'] ?? null,
        );

        return back()->with('status', $this->msg('تم إخفاء التوصية مؤقتاً.', 'Recommendation dismissed temporarily.'));
    }

    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            $permission,
            'b2c',
        );

        return $actor;
    }

    private function assertEntitled(SmartOrderingSite $site): void
    {
        abort_unless(
            DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->getKey())
                ->whereNull('branch_id')
                ->where('module', 'foodex_supply_intelligence')
                ->where('enabled', true)
                ->exists(),
            403,
            'FOODEX Supply Intelligence is not enabled for this restaurant.',
        );
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
