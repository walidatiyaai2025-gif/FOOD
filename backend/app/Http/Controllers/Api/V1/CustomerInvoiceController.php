<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\DisplayNumber;
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
            'Content-Disposition' => 'attachment; filename="invoice-'.(int) $model->getKey().'.pdf"',
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

        $references = [
            'platform_customer_id' => $platformCustomerId === null ? [] : [(int) $platformCustomerId],
            'b2b_customer_id' => $b2bCustomerId === null ? [] : [(int) $b2bCustomerId],
            'b2c_customer_id' => array_map('intval', $b2cCustomerIds),
            'customer_id' => array_map('intval', $legacyCustomerIds),
        ];

        $query = Invoice::query();

        // An invoice needs at least one owned identifier, but a matching legacy
        // identifier must never override a conflicting authoritative identity.
        $query->where(function (Builder $candidate) use ($references): void {
            $hasScope = false;
            foreach ($references as $column => $ids) {
                if ($ids === []) {
                    continue;
                }

                $candidate->orWhereIn($column, $ids);
                $hasScope = true;
            }

            if (! $hasScope) {
                $candidate->whereRaw('1 = 0');
            }
        });

        foreach ($references as $column => $ids) {
            $query->where(function (Builder $candidate) use ($column, $ids): void {
                $candidate->whereNull($column);
                if ($ids !== []) {
                    $candidate->orWhereIn($column, $ids);
                }
            });
        }

        // Imported invoices may have a missing channel, but a populated domain
        // identifier must not contradict a known invoice channel.
        $query->where(function (Builder $candidate): void {
            $candidate->whereNull('channel')
                ->orWhere(function (Builder $b2b): void {
                    $b2b->where('channel', 'b2b')->whereNull('b2c_customer_id');
                })
                ->orWhere(function (Builder $b2c): void {
                    $b2c->where('channel', 'b2c')->whereNull('b2b_customer_id');
                });
        });

        // A B2C customer belongs to one authoritative store; another store's
        // invoice is not owned merely because it carries that customer's ID.
        return $query->where(function (Builder $candidate): void {
            $candidate->whereNull('b2c_customer_id')
                ->orWhereNull('store_id')
                ->orWhereExists(function ($store): void {
                    $store->selectRaw('1')
                        ->from('b2c_customers')
                        ->whereColumn('b2c_customers.id', 'invoices.b2c_customer_id')
                        ->whereColumn('b2c_customers.store_id', 'invoices.store_id');
                });
        });
    }

    private function assertRequestedContext(Request $request, Invoice $invoice): void
    {
        // Laravel may normalize an explicitly empty query value to null.
        // Track key presence so an invalid input cannot fall back to a header.
        $hasInputStoreId = array_key_exists('store_id', $request->all());
        $hasHeaderStoreId = $request->headers->has('X-FOODEX-Store-ID');
        $storeId = $hasInputStoreId ? $request->input('store_id') : $request->header('X-FOODEX-Store-ID');
        if ($hasInputStoreId || $hasHeaderStoreId) {
            // A supplied but malformed store context must never bypass the
            // requested-store boundary, including when it shadows a valid header.
            abort_unless(is_string($storeId) || is_int($storeId), 404);
            $rawStoreId = trim((string) $storeId);
            abort_unless($rawStoreId !== '' && ctype_digit($rawStoreId), 404);
            $requestedStoreId = DisplayNumber::referenceId($rawStoreId);
            abort_unless($requestedStoreId !== null && (int) $invoice->store_id === $requestedStoreId, 404);
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
