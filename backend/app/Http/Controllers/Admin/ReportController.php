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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        $this->assertFilterIdentities($user, $validated);
        $data = $reports->run($user, $report, $validated, $perPage, ($page - 1) * $perPage);
        // More rows are reachable through pagination, so the UI is not truncated.
        $data['meta']['truncated'] = false;
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
            'options' => $this->options($user, $validated),
        ]);
    }

    public function lookup(Request $request, string $entity): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(in_array($entity, ['products', 'customers'], true), 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'store_id' => ['nullable', 'integer', 'min:1', 'exists:stores,id'],
            'channel' => ['nullable', 'required_with:customer_id', 'in:b2b,b2c'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
        ]);

        $scope = app(OperationalTenantScope::class);
        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $channel = isset($validated['channel']) ? (string) $validated['channel'] : null;
        $allowedStoreIds = $scope->allowedStoreIds($user, 'reports.view', $channel);
        abort_unless($superAdmin || $allowedStoreIds !== [], 403);

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        if (
            $storeId !== null
            && $superAdmin === false
            && in_array($storeId, $allowedStoreIds, true) === false
        ) {
            abort(403);
        }

        $term = trim((string) ($validated['q'] ?? ''));
        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageSize = 25;

        if ($entity === 'customers') {
            if ($channel === null) {
                throw ValidationException::withMessages([
                    'channel' => [__('reports.lookup.channel_required')],
                ]);
            }

            $query = $channel === 'b2b'
                ? DB::table('b2b_customers')
                    ->whereExists(function (Builder $orders) use ($allowedStoreIds, $storeId, $superAdmin): void {
                        $orders->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.b2b_customer_id', 'b2b_customers.id')
                            ->where('orders.channel', 'b2b')
                            ->when($storeId !== null, fn (Builder $query) => $query->where('orders.store_id', $storeId))
                            ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('orders.store_id', $allowedStoreIds));
                    })
                : DB::table('b2c_customers')
                    ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
                    ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('store_id', $allowedStoreIds));

            $rows = $query
                ->when($term !== '', function (Builder $query) use ($term): void {
                    $like = '%'.$term.'%';
                    $query->where(function (Builder $search) use ($like): void {
                        $search->where('name', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
                })
                ->orderBy('name')
                ->orderBy('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize + 1)
                ->get(['id', 'name', 'phone', 'email']);

            $hasMore = $rows->count() > $pageSize;
            $items = $rows->take($pageSize)->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'email' => $row->email === null ? null : (string) $row->email,
                'channel' => $channel,
            ])->values();

            return response()->json([
                'customers' => $items,
                'next_page' => $hasMore ? $page + 1 : null,
                'meta' => ['page' => $page, 'page_size' => $pageSize, 'has_more' => $hasMore],
            ]);
        }

        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $rows = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->when($storeId !== null, fn (Builder $query) => $query->where('catalogs.store_id', $storeId))
            ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('catalogs.store_id', $allowedStoreIds))
            ->when($channel !== null, fn (Builder $query) => $query->where('catalogs.channel', $channel))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('products.category_id', $categoryId))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(function (Builder $search) use ($like): void {
                    $search->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like);
                });
            })
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize + 1)
            ->get([
                'products.id',
                'products.name',
                'products.sku',
                'products.barcode',
                'products.is_active',
                'catalogs.store_id',
                'catalogs.channel',
            ]);

        $hasMore = $rows->count() > $pageSize;
        $items = $rows->take($pageSize)->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'sku' => $row->sku === null ? null : (string) $row->sku,
            'barcode' => $row->barcode === null ? null : (string) $row->barcode,
            'is_active' => (bool) $row->is_active,
            'store_id' => (int) $row->store_id,
            'channel' => (string) $row->channel,
        ])->values();

        return response()->json([
            'products' => $items,
            'next_page' => $hasMore ? $page + 1 : null,
            'meta' => ['page' => $page, 'page_size' => $pageSize, 'has_more' => $hasMore],
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
            'report' => ['required', 'in:orders,products,customers,operations,sales_exceptions'],
            'format' => ['required', 'in:xlsx,docx,pdf'],
        ]);

        $report = (string) $validated['report'];
        $format = (string) $validated['format'];
        unset($validated['report'], $validated['format']);

        $this->assertFilterIdentities($user, $validated);
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

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function options(User $user, array $filters): array
    {
        $scope = app(OperationalTenantScope::class);
        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $storeIds = $scope->allowedStoreIds($user, 'reports.view');

        $stores = DB::table('stores')
            ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('id', $storeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

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

        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $channel = isset($filters['channel']) ? (string) $filters['channel'] : null;
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $customerId = isset($filters['customer_id']) ? (int) $filters['customer_id'] : null;

        return [
            'stores' => $stores,
            'categories' => DB::table('categories')
                ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
                ->where('categories.is_active', true)
                ->when(! $superAdmin, fn (Builder $query) => $query->whereIn('catalogs.store_id', $storeIds))
                ->orderBy('categories.name')
                ->get([
                    'categories.id',
                    'categories.name',
                    'catalogs.store_id',
                    'catalogs.channel',
                ]),
            'selected_product' => $productId === null
                ? null
                : $this->productLookupQuery($user, $storeId, $channel, $categoryId)
                    ->where('products.id', $productId)
                    ->first([
                        'products.id',
                        'products.name',
                        'products.sku',
                        'products.barcode',
                        'products.is_active',
                        'catalogs.store_id',
                        'catalogs.channel',
                    ]),
            'selected_customer' => $customerId === null || $channel === null
                ? null
                : $this->customerLookupQuery($user, $channel, $storeId)
                    ->where('id', $customerId)
                    ->first(['id', 'name', 'phone', 'email']),
            'statuses' => $statuses,
            'payment_providers' => $providers,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function assertFilterIdentities(User $user, array $filters): void
    {
        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $channel = isset($filters['channel']) ? (string) $filters['channel'] : null;
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;

        if (isset($filters['product_id'])) {
            $exists = $this->productLookupQuery($user, $storeId, $channel, $categoryId)
                ->where('products.id', (int) $filters['product_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'product_id' => [__('reports.lookup.invalid_product')],
                ]);
            }
        }

        if (isset($filters['customer_id'])) {
            if ($channel === null) {
                throw ValidationException::withMessages([
                    'channel' => [__('reports.lookup.channel_required')],
                ]);
            }

            $exists = $this->customerLookupQuery($user, $channel, $storeId)
                ->where('id', (int) $filters['customer_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'customer_id' => [__('reports.lookup.invalid_customer')],
                ]);
            }
        }
    }

    private function productLookupQuery(
        User $user,
        ?int $storeId,
        ?string $channel,
        ?int $categoryId,
    ): Builder {
        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $storeIds = app(OperationalTenantScope::class)->allowedStoreIds($user, 'reports.view', $channel);

        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->when($storeId !== null, fn (Builder $query) => $query->where('catalogs.store_id', $storeId))
            ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('catalogs.store_id', $storeIds))
            ->when($channel !== null, fn (Builder $query) => $query->where('catalogs.channel', $channel))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('products.category_id', $categoryId));
    }

    private function customerLookupQuery(User $user, string $channel, ?int $storeId): Builder
    {
        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $storeIds = app(OperationalTenantScope::class)->allowedStoreIds($user, 'reports.view', $channel);

        if ($channel === 'b2b') {
            return DB::table('b2b_customers')
                ->whereExists(function (Builder $orders) use ($storeIds, $storeId, $superAdmin): void {
                    $orders->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.b2b_customer_id', 'b2b_customers.id')
                        ->where('orders.channel', 'b2b')
                        ->when($storeId !== null, fn (Builder $query) => $query->where('orders.store_id', $storeId))
                        ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('orders.store_id', $storeIds));
                });
        }

        return DB::table('b2c_customers')
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when(! $superAdmin && $storeId === null, fn (Builder $query) => $query->whereIn('store_id', $storeIds));
    }
}
