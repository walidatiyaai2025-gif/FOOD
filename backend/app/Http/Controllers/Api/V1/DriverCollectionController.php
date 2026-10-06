<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionAccount;
use App\Models\CollectionTransaction;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Remittance;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Services\DriverOrderService;
use App\Services\DriverTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DriverCollectionController extends Controller
{
    public function __construct(private readonly DriverTenantScope $driverTenants) {}

    public function collect(
        Request $request,
        int $assignment,
        CollectionCustodyService $custody,
        DriverOrderService $driverOrders,
    ): JsonResponse {
        [$user, $driver, $channel] = $this->driverContext($request);
        $model = $this->assignment($driver, $channel, $assignment);

        abort_unless(
            (string) $model->status === 'out_for_delivery',
            409,
            'Collection at delivery is available only while the assignment is out for delivery.',
        );

        $idempotencyKey = $this->idempotencyKey($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $payload = $driverOrders->payload($model);
        $orderPayload = $payload['order'] ?? null;
        abort_unless(is_array($orderPayload), 409, 'Authoritative order settlement is unavailable.');

        $settlement = $orderPayload['settlement'] ?? null;
        $invoice = $orderPayload['invoice'] ?? null;
        abort_unless(is_array($settlement), 409, 'Authoritative settlement is unavailable.');
        abort_unless(is_array($invoice) && isset($invoice['id']), 409, 'An issued invoice is required before collection.');

        $currency = strtoupper((string) ($settlement['currency'] ?? ''));
        $collectNow = round(max(0, (float) ($settlement['amount_to_collect_now'] ?? 0)), 3);
        $amount = round((float) $data['amount'], 3);

        abort_if($currency === '' || $collectNow <= 0.0001, 409, 'This delivery does not require collection.');
        if ($amount > $collectNow + 0.0005) {
            throw ValidationException::withMessages([
                'amount' => ['Collected amount cannot exceed the authoritative amount due now.'],
            ]);
        }

        $account = CollectionAccount::query()->firstOrCreate(
            [
                'actor_type' => 'driver',
                'actor_id' => $driver->getKey(),
                'store_id' => (int) $driver->store_id,
                'currency' => $currency,
            ],
            ['status' => 'active'],
        );
        abort_unless((string) $account->status === 'active', 409, 'Driver collection account is not active.');

        $transaction = $custody->collect(
            $account,
            $user,
            sprintf(
                'driver:%d:assignment:%d:collection:%s',
                (int) $driver->getKey(),
                (int) $model->getKey(),
                $idempotencyKey,
            ),
            $amount,
            $currency,
            'driver_app',
            [[
                'invoice_id' => (int) $invoice['id'],
                'amount' => $amount,
            ]],
        );

        $freshPayload = $driverOrders->payload($model->fresh());
        $freshOrder = $freshPayload['order'] ?? [];
        $freshSettlement = is_array($freshOrder) && is_array($freshOrder['settlement'] ?? null)
            ? $freshOrder['settlement']
            : $settlement;

        return response()->json([
            'data' => [
                'receipt' => $this->receiptPayload($transaction),
                'settlement' => $freshSettlement,
                'wallet' => $this->walletSummary($account, $custody),
            ],
        ], 201);
    }

    public function wallet(Request $request, CollectionCustodyService $custody): JsonResponse
    {
        [, $driver] = $this->driverContext($request);

        $accounts = CollectionAccount::query()
            ->where('actor_type', 'driver')
            ->where('actor_id', $driver->getKey())
            ->where('store_id', (int) $driver->store_id)
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
                        'reviewed_at' => $remittance->reviewed_at?->toIso8601String(),
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
        [$user, $driver] = $this->driverContext($request);
        $idempotencyKey = $this->idempotencyKey($request);
        $data = $request->validate([
            'collection_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = CollectionAccount::query()
            ->whereKey((int) $data['collection_account_id'])
            ->where('actor_type', 'driver')
            ->where('actor_id', $driver->getKey())
            ->where('store_id', (int) $driver->store_id)
            ->where('status', 'active')
            ->firstOrFail();

        $remittance = $custody->submitRemittance(
            $account,
            $user,
            sprintf(
                'driver:%d:remittance:%s',
                (int) $driver->getKey(),
                $idempotencyKey,
            ),
            round((float) $data['amount'], 3),
            (string) $account->currency,
            (string) $data['method'],
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return response()->json([
            'data' => [
                'remittance' => [
                    'id' => (int) $remittance->getKey(),
                    'amount' => (float) $remittance->amount,
                    'currency' => (string) $remittance->currency,
                    'method' => (string) $remittance->method,
                    'reference' => $remittance->reference,
                    'status' => (string) $remittance->status,
                    'note' => $remittance->note,
                    'created_at' => (string) $remittance->created_at,
                ],
                'wallet' => $this->walletSummary($account, $custody),
            ],
        ], 201);
    }

    /** @return array{0:User,1:Driver,2:string} */
    private function driverContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $driver = Driver::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
        abort_unless($driver instanceof Driver, 403, 'Active driver profile is required.');

        [$channel, $storeId] = $this->driverTenants->resolve($driver);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403);
        abort_unless($storeId === (int) $driver->store_id, 403);
        abort_unless($user->hasPermission("deliveries.{$channel}.execute"), 403);

        return [$user, $driver, $channel];
    }

    private function assignment(Driver $driver, string $channel, int $assignment): DriverAssignment
    {
        return DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->where('store_id', (int) $driver->store_id)
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
            ->firstOrFail();
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
    private function walletSummary(CollectionAccount $account, CollectionCustodyService $custody): array
    {
        return [
            'id' => (int) $account->getKey(),
            'currency' => (string) $account->currency,
            'status' => (string) $account->status,
            'custody_balance' => round($custody->custodyBalance($account), 3),
            'available_to_remit' => round($custody->availableToRemit($account), 3),
        ];
    }
}
