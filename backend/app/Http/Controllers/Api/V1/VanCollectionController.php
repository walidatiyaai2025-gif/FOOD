<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionAccount;
use App\Models\CollectionTransaction;
use App\Models\Invoice;
use App\Models\Remittance;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Services\VanCustomerCollectionContextService;
use App\Services\VanRuntimeVisitScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VanCollectionController extends Controller
{
    public function customerContext(
        Request $request,
        string $type,
        int $customer,
        VanCustomerCollectionContextService $context,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->customerStoreId($request, $actor, $type, $customer, $context);

        return response()->json([
            'data' => $context->context($type, $customer, $storeId),
        ]);
    }

    public function collect(
        Request $request,
        string $type,
        int $customer,
        CollectionCustodyService $custody,
        VanCustomerCollectionContextService $context,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->customerStoreId($request, $actor, $type, $customer, $context);
        $idempotencyKey = $this->idempotencyKey($request);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $column = $context->customerColumn($type);
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
                    'remaining_outstanding' => $context->outstandingAmount($invoice->fresh(), $type),
                    'wallet' => $this->walletSummary($account, $custody),
                ],
            ]);
        }

        $outstanding = $context->outstandingAmount($invoice, $type);
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

        $remaining = $context->outstandingAmount($invoice->fresh(), $type);

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

    private function customerStoreId(
        Request $request,
        User $actor,
        string $type,
        int $customer,
        VanCustomerCollectionContextService $context,
    ): int {
        $context->customerColumn($type);

        $storeIds = app(VanRuntimeVisitScope::class)
            ->query($request, $actor)
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
