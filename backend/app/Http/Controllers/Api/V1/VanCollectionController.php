<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionAccount;
use App\Models\CollectionTransaction;
use App\Models\Invoice;
use App\Models\Remittance;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\B2bAccountLedgerService;
use App\Services\CollectionCustodyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VanCollectionController extends Controller
{
    public function customerContext(
        Request $request,
        string $type,
        int $customer,
        B2bAccountLedgerService $ledger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->customerStoreId($request, $actor, $type, $customer);
        $invoices = $this->openInvoices($type, $customer, $storeId, $ledger);

        return response()->json([
            'data' => [
                'customer_type' => $type,
                'customer_id' => $customer,
                'store_id' => $storeId,
                'invoices' => $invoices,
                'outstanding_total_by_currency' => $invoices
                    ->groupBy('currency')
                    ->map(
                        fn (Collection $rows): float => round(
                            (float) $rows->sum('outstanding_amount'),
                            3,
                        ),
                    ),
            ],
        ]);
    }

    public function collect(
        Request $request,
        string $type,
        int $customer,
        CollectionCustodyService $custody,
        B2bAccountLedgerService $ledger,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->customerStoreId($request, $actor, $type, $customer);
        $idempotencyKey = $this->idempotencyKey($request);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $column = $this->customerColumn($type);
        $invoice = Invoice::query()
            ->whereKey((int) $data['invoice_id'])
            ->where($column, $customer)
            ->where('store_id', $storeId)
            ->where('channel', $type)
            ->whereIn('status', ['issued', 'reissued'])
            ->firstOrFail();

        $amount = round((float) $data['amount'], 3);
        $currency = strtoupper(trim((string) $invoice->currency));
        abort_if($currency === '', 409, 'Invoice currency is unavailable.');

        $account = CollectionAccount::query()->firstOrCreate(
            [
                'actor_type' => 'van_rep',
                'actor_id' => $actor->getKey(),
                'store_id' => $storeId,
                'currency' => $currency,
            ],
            ['status' => 'active'],
        );
        abort_unless((string) $account->status === 'active', 409, 'Van collection account is not active.');

        $operationKey = sprintf(
            'van:%d:%s:%d:invoice:%d:collection:%s',
            (int) $actor->getKey(),
            $type,
            $customer,
            (int) $invoice->getKey(),
            $idempotencyKey,
        );

        $existing = CollectionTransaction::query()
            ->where('idempotency_key', $operationKey)
            ->where('collection_account_id', $account->getKey())
            ->first();
        if ($existing instanceof CollectionTransaction) {
            if (abs((float) $existing->amount - $amount) > 0.0005) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency-Key was already used with a different collection amount.'],
                ]);
            }

            return response()->json([
                'data' => [
                    'receipt' => $this->receiptPayload($existing),
                    'invoice_id' => (int) $invoice->getKey(),
                    'remaining_outstanding' => $this->outstandingAmount($invoice->fresh(), $type, $ledger),
                    'wallet' => $this->walletSummary($account, $custody),
                ],
            ]);
        }

        $outstanding = $this->outstandingAmount($invoice, $type, $ledger);
        abort_if($outstanding <= 0.0001, 409, 'This invoice does not have an outstanding collectible balance.');

        if ($amount > $outstanding + 0.0005) {
            throw ValidationException::withMessages([
                'amount' => ['Collected amount cannot exceed the authoritative invoice outstanding balance.'],
            ]);
        }

        $transaction = $custody->collect(
            $account,
            $actor,
            $operationKey,
            $amount,
            $currency,
            'van_app',
            [[
                'invoice_id' => (int) $invoice->getKey(),
                'amount' => $amount,
            ]],
        );

        $remaining = $this->outstandingAmount($invoice->fresh(), $type, $ledger);

        return response()->json([
            'data' => [
                'receipt' => $this->receiptPayload($transaction),
                'invoice_id' => (int) $invoice->getKey(),
                'remaining_outstanding' => $remaining,
                'wallet' => $this->walletSummary($account, $custody),
            ],
        ], 201);
    }

    public function wallet(Request $request, CollectionCustodyService $custody): JsonResponse
    {
        $actor = $this->actor($request);

        $accounts = CollectionAccount::query()
            ->where('actor_type', 'van_rep')
            ->where('actor_id', $actor->getKey())
            ->orderBy('store_id')
            ->orderBy('currency')
            ->get();

        return response()->json([
            'data' => $accounts->map(function (CollectionAccount $account) use ($custody): array {
                $transactions = CollectionTransaction::query()
                    ->where('collection_account_id', $account->getKey())
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->map(fn (CollectionTransaction $transaction): array => $this->receiptPayload($transaction))
                    ->values();

                $remittances = Remittance::query()
                    ->where('collection_account_id', $account->getKey())
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->map(fn (Remittance $remittance): array => [
                        'id' => (int) $remittance->getKey(),
                        'amount' => (float) $remittance->amount,
                        'currency' => (string) $remittance->currency,
                        'method' => (string) $remittance->method,
                        'reference' => $remittance->reference,
                        'status' => (string) $remittance->status,
                        'note' => $remittance->note,
                        'created_at' => (string) $remittance->created_at,
                        'reviewed_at' => $remittance->reviewed_at === null
                            ? null
                            : (string) $remittance->reviewed_at,
                    ])
                    ->values();

                return [
                    ...$this->walletSummary($account, $custody),
                    'transactions' => $transactions,
                    'remittances' => $remittances,
                ];
            })->values(),
        ]);
    }

    public function remit(Request $request, CollectionCustodyService $custody): JsonResponse
    {
        $actor = $this->actor($request);
        $idempotencyKey = $this->idempotencyKey($request);

        $data = $request->validate([
            'collection_account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = CollectionAccount::query()
            ->whereKey((int) $data['collection_account_id'])
            ->where('actor_type', 'van_rep')
            ->where('actor_id', $actor->getKey())
            ->where('status', 'active')
            ->firstOrFail();

        $amount = round((float) $data['amount'], 3);
        $method = trim((string) $data['method']);
        $operationKey = sprintf(
            'van:%d:remittance:%s',
            (int) $actor->getKey(),
            $idempotencyKey,
        );

        $existing = Remittance::query()
            ->where('idempotency_key', $operationKey)
            ->where('collection_account_id', $account->getKey())
            ->first();
        if ($existing instanceof Remittance) {
            if (
                abs((float) $existing->amount - $amount) > 0.0005
                || (string) $existing->method !== $method
            ) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency-Key was already used with different remittance details.'],
                ]);
            }

            return response()->json([
                'data' => [
                    'remittance' => $this->remittancePayload($existing),
                    'wallet' => $this->walletSummary($account, $custody),
                ],
            ]);
        }

        $remittance = $custody->submitRemittance(
            $account,
            $actor,
            $operationKey,
            $amount,
            (string) $account->currency,
            $method,
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return response()->json([
            'data' => [
                'remittance' => $this->remittancePayload($remittance),
                'wallet' => $this->walletSummary($account, $custody),
            ],
        ], 201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function customerStoreId(Request $request, User $actor, string $type, int $customer): int
    {
        $this->customerColumn($type);

        $storeIds = VanVisit::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('customer_type', $type)
            ->where('customer_id', $customer)
            ->whereNotNull('store_id')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        abort_if($storeIds->isEmpty(), 404);

        $requested = $request->input('store_id');
        if ($requested === null) {
            if ($storeIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'store_id' => ['Store is required when the customer is in more than one assigned Van scope.'],
                ]);
            }

            return (int) $storeIds->first();
        }

        $storeId = (int) $requested;
        abort_unless($storeIds->contains($storeId), 404);

        return $storeId;
    }

    private function customerColumn(string $type): string
    {
        return match ($type) {
            'b2b' => 'b2b_customer_id',
            'b2c' => 'b2c_customer_id',
            default => abort(404),
        };
    }

    /** @return Collection<int,array{id:int,number:string,currency:string,total:float,outstanding_amount:float,due_at:string|null}> */
    private function openInvoices(
        string $type,
        int $customer,
        int $storeId,
        B2bAccountLedgerService $ledger,
    ): Collection {
        $column = $this->customerColumn($type);

        return Invoice::query()
            ->where($column, $customer)
            ->where('store_id', $storeId)
            ->where('channel', $type)
            ->whereIn('status', ['issued', 'reissued'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($type, $ledger): array {
                $outstanding = $this->outstandingAmount($invoice, $type, $ledger);

                return [
                    'id' => (int) $invoice->getKey(),
                    'number' => (string) $invoice->invoice_number,
                    'currency' => strtoupper((string) $invoice->currency),
                    'total' => round((float) $invoice->total, 3),
                    'outstanding_amount' => $outstanding,
                    'due_at' => $invoice->due_at === null ? null : (string) $invoice->due_at,
                ];
            })
            ->filter(fn (array $invoice): bool => (float) $invoice['outstanding_amount'] > 0.0001)
            ->values();
    }

    private function outstandingAmount(
        Invoice $invoice,
        string $type,
        B2bAccountLedgerService $ledger,
    ): float {
        if ($type === 'b2b') {
            return round((float) $ledger->invoiceAmounts($invoice)['outstanding_amount'], 3);
        }

        $paid = (float) DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereIn('status', ['paid', 'captured'])
            ->sum('amount');

        return round(max((float) $invoice->total - $paid, 0), 3);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if (
            strlen($key) < 8
            || strlen($key) > 72
            || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency-Key must be 8-72 characters using letters, numbers, dot, underscore, colon or dash.',
                ],
            ]);
        }

        return $key;
    }

    /** @return array<string,mixed> */
    private function receiptPayload(CollectionTransaction $transaction): array
    {
        return [
            'id' => (int) $transaction->getKey(),
            'payment_id' => $transaction->payment_id === null ? null : (int) $transaction->payment_id,
            'amount' => (float) $transaction->amount,
            'currency' => (string) $transaction->currency,
            'status' => (string) $transaction->status,
            'source' => (string) $transaction->source,
            'created_at' => (string) $transaction->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function remittancePayload(Remittance $remittance): array
    {
        return [
            'id' => (int) $remittance->getKey(),
            'amount' => (float) $remittance->amount,
            'currency' => (string) $remittance->currency,
            'method' => (string) $remittance->method,
            'reference' => $remittance->reference,
            'status' => (string) $remittance->status,
            'note' => $remittance->note,
            'created_at' => (string) $remittance->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function walletSummary(CollectionAccount $account, CollectionCustodyService $custody): array
    {
        return [
            'id' => (int) $account->getKey(),
            'store_id' => $account->store_id === null ? null : (int) $account->store_id,
            'currency' => (string) $account->currency,
            'status' => (string) $account->status,
            'custody_balance' => round($custody->custodyBalance($account), 3),
            'available_to_remit' => round($custody->availableToRemit($account), 3),
        ];
    }
}
