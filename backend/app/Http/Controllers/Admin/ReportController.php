<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementReportService;
use App\Services\ReportExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReportController extends Controller
{
    public function index(Request $request, ManagementReportService $reports): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $report = (string) $request->query('report', 'orders');
        $filters = $request->validate($this->rules());
        $data = $reports->run($user, $report, $filters);

        return view('admin.reports', [
            'report' => $report,
            'catalog' => $reports->catalog(),
            'data' => $data,
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
            'customer_id' => ['nullable', 'integer', 'min:1', 'exists:customers,id'],
            'payment_provider' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, mixed> */
    private function options(User $user): array
    {
        $global = $user->hasPermission('reports.view');
        $storeIds = $global
            ? []
            : $user->storeRoleAssignments()
                ->whereHas('role.permissions', fn ($query) => $query->where('permissions.code', 'reports.view'))
                ->pluck('store_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

        $stores = DB::table('stores')
            ->when(! $global, fn (Builder $query) => $query->whereIn('id', $storeIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = DB::table('products')
            ->when(! $global, function (Builder $query) use ($storeIds): void {
                $query->whereExists(function (Builder $sub) use ($storeIds): void {
                    $sub->selectRaw('1')
                        ->from('store_products')
                        ->whereColumn('store_products.product_id', 'products.id')
                        ->whereIn('store_products.store_id', $storeIds);
                });
            })
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name', 'sku']);

        $customers = DB::table('customers')
            ->when(! $global, function (Builder $query) use ($storeIds): void {
                $query->whereExists(function (Builder $sub) use ($storeIds): void {
                    $sub->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.customer_id', 'customers.id')
                        ->whereIn('orders.store_id', $storeIds);
                });
            })
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name', 'type']);

        $statuses = DB::table('orders')
            ->when(! $global, fn (Builder $query) => $query->whereIn('store_id', $storeIds))
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter(fn ($status): bool => is_string($status) && $status !== '')
            ->values()
            ->all();

        $providers = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->when(! $global, fn (Builder $query) => $query->whereIn('orders.store_id', $storeIds))
            ->distinct()
            ->orderBy('payments.provider')
            ->pluck('payments.provider')
            ->filter(fn ($provider): bool => is_string($provider) && $provider !== '')
            ->values()
            ->all();

        return [
            'stores' => $stores,
            'categories' => DB::table('categories')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => $products,
            'customers' => $customers,
            'statuses' => $statuses,
            'payment_providers' => $providers,
        ];
    }
}
