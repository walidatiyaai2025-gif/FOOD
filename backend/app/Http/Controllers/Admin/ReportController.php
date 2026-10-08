<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementReportService;
use App\Services\OperationalTenantScope;
use App\Services\ReportExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReportController extends Controller
{
    public function index(Request $request, ManagementReportService $reports): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $report = (string) $request->query('report', 'orders');
        $validated = $request->validate([
            ...$this->rules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = (int) ($validated['per_page'] ?? 25);
        unset($validated['page'], $validated['per_page']);

        $data = $reports->run($user, $report, $validated, $perPage, ($page - 1) * $perPage);
        $rowsPaginator = new LengthAwarePaginator(
            $data['rows'],
            (int) ($data['meta']['row_count'] ?? count($data['rows'])),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->except('page'),
            ],
        );

        return view('admin.reports', [
            'report' => $report,
            'catalog' => $reports->catalog(),
            'data' => $data,
            'rowsPaginator' => $rowsPaginator,
            'options' => $this->options($user),
        ]);
    }

    public function export(
        Request $request,
        ManagementReportService $reports,
        ReportExportService $exports,
        AuditLogger $audit,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            ...$this->rules(),
            'report' => ['required', 'in:orders,products,customers,operations'],
            'format' => ['required', 'in:xlsx,docx,pdf'],
        ]);

        $report = (string) $validated['report'];
        $format = (string) $validated['format'];
        unset($validated['report'], $validated['format']);

        $reports->authorizeExport($user, $validated);
        $data = $reports->run($user, $report, $validated, ManagementReportService::EXPORT_LIMIT);
        $locale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'en';
        $file = $exports->build($data, $format, $locale);
        $filename = $exports->filename($data, $file['extension']);

        $audit->record('report.exported', $user, null, null, [
            'report' => $report,
            'format' => $format,
            'filters' => $data['filters'],
            'rows' => count((array) $data['rows']),
            'truncated' => (bool) data_get($data, 'meta.truncated', false),
            'export_limit' => ManagementReportService::EXPORT_LIMIT,
        ], $request);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-FOODEX-Export-Limit' => (string) ManagementReportService::EXPORT_LIMIT,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1', 'exists:stores,id'],
            'channel' => ['nullable', 'in:b2b,b2c'],
            'status' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer', 'min:1', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_provider' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, mixed> */
    private function options(User $user): array
    {
        $scope = app(OperationalTenantScope::class);
        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $storeIds = $scope->allowedStoreIds($user, 'reports.view');

        $stores = DB::table('stores')
            ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('id', $storeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('catalogs.store_id', $storeIds))
            ->orderBy('products.name')
            ->limit(250)
            ->get(['products.id', 'products.name', 'products.sku']);

        $b2cStoreIds = $scope->allowedStoreIds($user, 'reports.view', 'b2c');
        $b2bStoreIds = $scope->allowedStoreIds($user, 'reports.view', 'b2b');

        $customers = collect();
        if ($b2cStoreIds !== []) {
            $customers = $customers->concat(
                DB::table('b2c_customers')
                    ->whereIn('store_id', $b2cStoreIds)
                    ->orderBy('name')
                    ->limit(250)
                    ->get(['id', 'name'])
                    ->map(fn (object $row): object => (object) [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'type' => 'b2c',
                    ]),
            );
        }
        if ($b2bStoreIds !== []) {
            $customers = $customers->concat(
                DB::table('b2b_customers')
                    ->whereExists(function (Builder $sub) use ($b2bStoreIds): void {
                        $sub->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.b2b_customer_id', 'b2b_customers.id')
                            ->whereIn('orders.store_id', $b2bStoreIds)
                            ->where('orders.channel', 'b2b');
                    })
                    ->orderBy('name')
                    ->limit(250)
                    ->get(['id', 'name'])
                    ->map(fn (object $row): object => (object) [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'type' => 'b2b',
                    ]),
            );
        }
        $customers = $customers->take(250)->values();

        $statuses = DB::table('orders')
            ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('store_id', $storeIds))
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter(fn ($status): bool => is_string($status) && $status !== '')
            ->values()
            ->all();

        $providers = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('orders.store_id', $storeIds))
            ->distinct()
            ->orderBy('payments.provider')
            ->pluck('payments.provider')
            ->filter(fn ($provider): bool => is_string($provider) && $provider !== '')
            ->values()
            ->all();

        return [
            'stores' => $stores,
            'categories' => DB::table('categories')
                ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
                ->where('categories.is_active', true)
                ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('catalogs.store_id', $storeIds))
                ->orderBy('categories.name')
                ->get(['categories.id', 'categories.name']),
            'products' => $products,
            'customers' => $customers,
            'statuses' => $statuses,
            'payment_providers' => $providers,
        ];
    }
}
