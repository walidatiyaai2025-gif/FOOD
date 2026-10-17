<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\RestaurantAnalyticsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SmartOrderingAnalyticsController extends Controller
{
    public function index(
        Request $request,
        OperationalTenantScope $scope,
        RestaurantAnalyticsService $analytics,
    ): View {
        [$actor, $storeIds, $filters] = $this->context($request, $scope);
        $dashboard = $analytics->dashboard($storeIds, $filters);

        $productIds = DB::table('smart_ordering_analytics_product_daily')
            ->whereIn('store_id', $storeIds)
            ->distinct()
            ->limit(250)
            ->pluck('product_id');
        $categoryIds = DB::table('smart_ordering_analytics_category_daily')
            ->whereIn('store_id', $storeIds)
            ->distinct()
            ->limit(250)
            ->pluck('category_id');

        return view('admin.smart-ordering-analytics', [
            'user' => $actor,
            'dashboard' => $dashboard,
            'filters' => $dashboard['filters'],
            'stores' => DB::table('stores')->whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name', 'code']),
            'branches' => DB::table('smart_ordering_branches as b')
                ->join('smart_ordering_sites as s', 's.id', '=', 'b.site_id')
                ->whereIn('s.store_id', $storeIds)
                ->where('b.is_active', true)
                ->orderBy('b.name_en')
                ->limit(250)
                ->get(['b.id', 'b.name_ar', 'b.name_en', 's.store_id']),
            'qrCodes' => DB::table('smart_ordering_qr_codes')
                ->whereIn('store_id', $storeIds)
                ->where('is_enabled', true)
                ->orderBy('label')
                ->limit(250)
                ->get(['public_id', 'label', 'type', 'source', 'campaign']),
            'products' => DB::table('products')
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
            'categories' => DB::table('categories')
                ->whereIn('id', $categoryIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'smart_ordering',
        ]);
    }

    public function export(
        Request $request,
        OperationalTenantScope $scope,
        RestaurantAnalyticsService $analytics,
    ): StreamedResponse {
        [$actor, $storeIds, $filters] = $this->context($request, $scope, 'smart_ordering.analytics.export');
        $dashboard = $analytics->dashboard($storeIds, $filters);

        return response()->streamDownload(function () use ($dashboard): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['metric', 'value', 'formula_version']);
            foreach ($dashboard['kpis'] as $key => $value) {
                fputcsv($out, [$key, $value, $dashboard['formula_version']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['qr_public_id', 'source', 'campaign', 'views', 'orders', 'conversion', 'revenue', 'aov']);
            foreach ($dashboard['sources'] as $row) {
                fputcsv($out, [$row['qr_public_id'], $row['source'], $row['campaign'], $row['views'], $row['orders'], $row['conversion'], $row['revenue'], $row['aov']]);
            }
            fclose($out);
        }, 'smart-ordering-analytics-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{0:User,1:list<int>,2:array<string,mixed>}
     */
    private function context(
        Request $request,
        OperationalTenantScope $scope,
        string $permission = 'smart_ordering.analytics.view',
    ): array {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $allowed = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, $permission, $request);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'fulfillment' => ['nullable', Rule::in(['dine_in', 'pickup', 'delivery'])],
            'qr_public_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:64'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'offer' => ['nullable', 'string', 'max:120'],
            'customer_type' => ['nullable', Rule::in(['new', 'repeat'])],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($filters['store_id'])) {
            $selected = (int) $filters['store_id'];
            if (! in_array($selected, $allowed, true)) {
                abort(404);
            }
            $allowed = [$selected];
        }

        if (! empty($filters['branch_id'])) {
            $branchAllowed = DB::table('smart_ordering_branches as b')
                ->join('smart_ordering_sites as s', 's.id', '=', 'b.site_id')
                ->where('b.id', (int) $filters['branch_id'])
                ->whereIn('s.store_id', $allowed)
                ->exists();
            abort_unless($branchAllowed, 404);
        }

        return [$actor, $allowed, $filters];
    }
}
