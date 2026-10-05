<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CustomerInvoiceController extends Controller
{
    public function index(Request $request, InvoiceService $invoices): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $paginator = $this->owned($user)
            ->when(isset($validated['store_id']), fn (Builder $q) => $q->where('store_id', (int) $validated['store_id']))
            ->when(isset($validated['channel']), fn (Builder $q) => $q->where('channel', (string) $validated['channel']))
            ->when(isset($validated['status']), fn (Builder $q) => $q->where('status', (string) $validated['status']))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Invoice $invoice): array => $invoices->payload($invoice, false))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $invoice, InvoiceService $invoices): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = $this->owned($user)->whereKey($invoice)->firstOrFail();
        $this->assertRequestedContext($request, $model);

        return response()->json(['data' => $invoices->payload($model, true)]);
    }

    public function download(Request $request, int $invoice, InvoiceService $invoices): Response
    {
        $validated = $request->validate(['locale' => ['nullable', Rule::in(['ar', 'en'])]]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = $this->owned($user)->whereKey($invoice)->firstOrFail();
        $this->assertRequestedContext($request, $model);
        $locale = (string) ($validated['locale'] ?? $user->locale ?? 'en');
        try {
            $pdf = $invoices->renderPdf($model, $locale);
        } catch (\RuntimeException) {
            abort(503, 'PDF generation is temporarily unavailable.');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$model->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return Builder<Invoice> */
    private function owned(User $user): Builder
    {
        $platformCustomerId = DB::table('platform_customers')->where('user_id', $user->getKey())->value('id');
        $b2bCustomerId = DB::table('b2b_customers')->where('user_id', $user->getKey())->value('id');
        $b2cCustomerIds = DB::table('b2c_customers')->where('user_id', $user->getKey())->pluck('id')->all();
        $legacyCustomerIds = DB::table('customers')->where('user_id', $user->getKey())->pluck('id')->all();

        return Invoice::query()->where(function (Builder $query) use (
            $platformCustomerId,
            $b2bCustomerId,
            $b2cCustomerIds,
            $legacyCustomerIds,
        ): void {
            $hasScope = false;
            if ($platformCustomerId !== null) {
                $query->orWhere('platform_customer_id', (int) $platformCustomerId);
                $hasScope = true;
            }
            if ($b2bCustomerId !== null) {
                $query->orWhere('b2b_customer_id', (int) $b2bCustomerId);
                $hasScope = true;
            }
            if ($b2cCustomerIds !== []) {
                $query->orWhereIn('b2c_customer_id', array_map('intval', $b2cCustomerIds));
                $hasScope = true;
            }
            if ($legacyCustomerIds !== []) {
                $query->orWhereIn('customer_id', array_map('intval', $legacyCustomerIds));
                $hasScope = true;
            }
            if (! $hasScope) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    private function assertRequestedContext(Request $request, Invoice $invoice): void
    {
        $storeId = $request->input('store_id') ?? $request->query('store_id') ?? $request->header('X-FOODEX-Store-ID');
        if (is_numeric($storeId) && (int) $storeId > 0) {
            abort_unless((int) $invoice->store_id === (int) $storeId, 404);
        }

        $channel = strtolower(trim((string) (
            $request->input('channel') ?? $request->query('channel') ?? $request->header('X-FOODEX-Customer-Domain', '')
        )));
        if ($channel !== '') {
            abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);
            abort_unless($channel === strtolower((string) $invoice->channel), 404);
        }
    }
}
