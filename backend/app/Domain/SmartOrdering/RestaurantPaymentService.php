<?php

namespace App\Domain\SmartOrdering;

use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class RestaurantPaymentService
{
    private const OPERATIONS = ['authorize', 'capture', 'void', 'refund'];

    private const PAYMENT_STATUSES = [
        'pending',
        'authorized',
        'captured',
        'partially_refunded',
        'refunded',
        'voided',
        'failed',
    ];

    public function __construct(
        private readonly RestaurantPaymentProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        Payment $payment,
        string $operation,
        ?string $amount,
        string $idempotencyKey,
        User $actor,
    ): object {
        $operation = strtolower(trim($operation));
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw ValidationException::withMessages(['operation' => ['Unsupported payment operation.']]);
        }

        $provider = trim((string) $payment->provider);
        try {
            $adapter = $this->providers->provider($provider);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'provider' => ['The configured payment provider is unavailable.'],
            ]);
        }
        if (! $adapter->supports($operation)) {
            throw ValidationException::withMessages([
                'operation' => ['This payment provider does not support that operation.'],
            ]);
        }

        $normalizedAmount = $operation === 'void'
            ? null
            : $this->normalizeAmount($amount, 'amount');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['A valid payment idempotency key is required.'],
            ]);
        }

        $requestHash = hash('sha256', json_encode([
            'payment_id' => (int) $payment->getKey(),
            'provider' => $provider,
            'operation' => $operation,
            'amount' => $normalizedAmount,
            'currency' => strtoupper((string) $payment->currency),
        ], JSON_THROW_ON_ERROR));

        $reserved = DB::transaction(function () use (
            $payment,
            $operation,
            $normalizedAmount,
            $idempotencyKey,
            $requestHash,
            $actor,
            $provider,
        ): object {
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRestaurantPayment($lockedPayment);
            $existing = DB::table('restaurant_payment_operations')
                ->where('payment_id', $lockedPayment->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key is already bound to a different payment request.'],
                    ]);
                }
                if ((string) $existing->status === 'pending') {
                    abort(409, 'The payment operation is still being processed.');
                }

                return $existing;
            }

            $this->assertOperationAllowed($lockedPayment, $operation, $normalizedAmount);

            $publicId = (string) Str::uuid();
            $id = (int) DB::table('restaurant_payment_operations')->insertGetId([
                'public_id' => $publicId,
                'payment_id' => $lockedPayment->getKey(),
                'provider' => $provider,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'pending',
                'amount' => $normalizedAmount,
                'currency' => strtoupper((string) $lockedPayment->currency),
                'provider_reference' => $lockedPayment->provider_reference,
                'requested_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('restaurant_payment_operations')->where('id', $id)->firstOrFail();
        }, 3);

        if ((string) $reserved->status !== 'pending') {
            return $reserved;
        }

        try {
            $result = $adapter->execute(
                $operation,
                $payment->fresh(),
                $normalizedAmount ?? '0.000',
                strtoupper((string) $payment->currency),
                ['operation_public_id' => (string) $reserved->public_id],
            );
        } catch (Throwable $exception) {
            DB::table('restaurant_payment_operations')
                ->where('id', $reserved->id)
                ->update([
                    'status' => 'failed',
                    'provider_status' => 'transport_error',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            throw ValidationException::withMessages([
                'provider' => ['The payment provider could not process the request.'],
            ]);
        }

        $final = DB::transaction(function () use ($reserved, $payment, $operation, $normalizedAmount, $result): object {
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $lockedOperation = DB::table('restaurant_payment_operations')
                ->where('id', $reserved->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedOperation->status !== 'pending') {
                return $lockedOperation;
            }

            $safeMetadata = $this->safeMetadata($result->safeMetadata);
            $mismatch = $this->settlementMismatch(
                $lockedPayment,
                $result->settlementGrossAmount,
                $result->settlementFeeAmount,
                $result->settlementNetAmount,
                $operation,
                $normalizedAmount,
            );

            DB::table('restaurant_payment_operations')
                ->where('id', $lockedOperation->id)
                ->update([
                    'status' => $result->succeeded ? 'succeeded' : 'failed',
                    'provider_reference' => $result->providerReference ?: $lockedPayment->provider_reference,
                    'provider_status' => $this->trimNullable($result->providerStatus, 100),
                    'settlement_gross_amount' => $this->nullableAmount($result->settlementGrossAmount),
                    'settlement_fee_amount' => $this->nullableAmount($result->settlementFeeAmount),
                    'settlement_net_amount' => $this->nullableAmount($result->settlementNetAmount),
                    'settled_at' => $result->settledAt?->utc(),
                    'mismatch_code' => $mismatch,
                    'safe_metadata' => $safeMetadata === [] ? null : json_encode($safeMetadata, JSON_THROW_ON_ERROR),
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($result->succeeded) {
                $before = $lockedPayment->toArray();
                $lockedPayment->forceFill([
                    'provider_reference' => $result->providerReference ?: $lockedPayment->provider_reference,
                    'status' => $this->statusAfterSuccess($lockedPayment, $operation, $normalizedAmount),
                    'metadata' => $this->mergeSafePaymentMetadata(
                        (array) $lockedPayment->metadata,
                        $operation,
                        $result->providerStatus,
                        $safeMetadata,
                    ),
                ])->save();
                $actorId = $lockedOperation->requested_by;
                if ($actorId !== null && ($actor = User::query()->find((int) $actorId)) instanceof User) {
                    $this->audit->record(
                        'smart_ordering.payment_'.$operation,
                        $actor,
                        $lockedPayment,
                        $before,
                        $lockedPayment->fresh()->toArray(),
                    );
                }
            }

            return DB::table('restaurant_payment_operations')->where('id', $lockedOperation->id)->firstOrFail();
        }, 3);

        if ((string) $final->status !== 'succeeded') {
            throw ValidationException::withMessages([
                'provider' => ['The payment provider rejected the operation.'],
            ]);
        }

        return $final;
    }

    public function reconcileWebhook(string $provider, Request $request): Payment
    {
        try {
            $adapter = $this->providers->provider($provider);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($adapter->verifyWebhook($request), 401, 'Invalid payment webhook signature.');
        $event = $adapter->webhook($request);
        abort_if(trim($event->eventId) === '' || trim($event->providerReference) === '', 422);

        $operation = strtolower(trim($event->operation));
        abort_unless(in_array($operation, [...self::OPERATIONS, 'settlement'], true), 422);
        $paymentStatus = strtolower(trim($event->paymentStatus));
        abort_unless(in_array($paymentStatus, self::PAYMENT_STATUSES, true), 422);

        return DB::transaction(function () use ($provider, $event, $operation, $paymentStatus): Payment {
            $payment = Payment::query()
                ->where('provider', $provider)
                ->where('provider_reference', $event->providerReference)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRestaurantPayment($payment);

            $existing = DB::table('restaurant_payment_operations')
                ->where('provider', $provider)
                ->where('provider_event_id', $event->eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $payment->refresh();
            }

            $amount = $event->amount === null ? null : $this->normalizeAmount($event->amount, 'amount');
            $safeMetadata = $this->safeMetadata($event->safeMetadata);
            $mismatch = $this->settlementMismatch(
                $payment,
                $event->settlementGrossAmount,
                $event->settlementFeeAmount,
                $event->settlementNetAmount,
                $operation,
                $amount,
            );
            $requestHash = hash('sha256', json_encode([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'provider_reference' => $event->providerReference,
                'operation' => $operation,
                'payment_status' => $paymentStatus,
                'amount' => $amount,
            ], JSON_THROW_ON_ERROR));

            DB::table('restaurant_payment_operations')->insert([
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->getKey(),
                'provider' => $provider,
                'operation' => $operation,
                'idempotency_key' => 'webhook:'.$event->eventId,
                'request_hash' => $requestHash,
                'status' => 'succeeded',
                'amount' => $amount,
                'currency' => strtoupper((string) $payment->currency),
                'provider_reference' => $event->providerReference,
                'provider_event_id' => $event->eventId,
                'provider_status' => $this->trimNullable($event->providerStatus, 100),
                'settlement_gross_amount' => $this->nullableAmount($event->settlementGrossAmount),
                'settlement_fee_amount' => $this->nullableAmount($event->settlementFeeAmount),
                'settlement_net_amount' => $this->nullableAmount($event->settlementNetAmount),
                'settled_at' => $event->settledAt?->utc(),
                'mismatch_code' => $mismatch,
                'safe_metadata' => $safeMetadata === [] ? null : json_encode($safeMetadata, JSON_THROW_ON_ERROR),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payment->forceFill([
                'status' => $paymentStatus,
                'metadata' => $this->mergeSafePaymentMetadata(
                    (array) $payment->metadata,
                    $operation,
                    $event->providerStatus,
                    $safeMetadata,
                ),
            ])->save();

            return $payment->refresh();
        }, 3);
    }

    /** @param list<int> $storeIds
     * @return list<string>
     */
    public function providerOptions(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('smart_ordering_order_contexts as soc', 'soc.order_id', '=', 'o.id')
            ->whereIn('o.store_id', $storeIds)
            ->whereNotNull('p.provider')
            ->where('p.provider', '<>', '')
            ->distinct()
            ->orderBy('p.provider')
            ->pluck('p.provider')
            ->map(static fn ($provider): string => (string) $provider)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $storeIds
     * @param  array{payment_status?:string,payment_provider?:string,reconciliation?:string}  $filters
     * @return array{0:LengthAwarePaginator,1:list<array<string,mixed>>}
     */
    public function reconciliationPage(array $storeIds, array $filters, int $perPage = 25): array
    {
        $query = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('smart_ordering_order_contexts as soc', 'soc.order_id', '=', 'o.id')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
            ->whereIn('o.store_id', $storeIds)
            ->when(
                isset($filters['payment_status']),
                fn ($q) => $q->where('p.status', $filters['payment_status']),
            )
            ->when(
                isset($filters['payment_provider']) && trim((string) $filters['payment_provider']) !== '',
                fn ($q) => $q->where('p.provider', trim((string) $filters['payment_provider'])),
            )
            ->when(
                ($filters['reconciliation'] ?? null) === 'mismatch',
                fn ($q) => $q->whereExists(fn ($ops) => $ops
                    ->selectRaw('1')
                    ->from('restaurant_payment_operations as rpo')
                    ->whereColumn('rpo.payment_id', 'p.id')
                    ->whereNotNull('rpo.mismatch_code')),
            )
            ->orderByDesc('p.id')
            ->select([
                'p.id',
                'p.order_id',
                'p.provider',
                'p.provider_reference',
                'p.status',
                'p.amount',
                'p.currency',
                'p.created_at',
                'o.order_number',
                'o.store_id',
                'soc.public_reference as order_reference',
                DB::raw('COALESCE(b.name_en, b.name_ar) as branch_name'),
            ]);

        $paginator = $query->paginate(max(10, min(100, $perPage)))->withQueryString();
        $paymentIds = collect($paginator->items())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $operations = $paymentIds === []
            ? collect()
            : DB::table('restaurant_payment_operations')
                ->whereIn('payment_id', $paymentIds)
                ->where('status', 'succeeded')
                ->orderBy('id')
                ->get()
                ->groupBy('payment_id');

        $rows = collect($paginator->items())->map(function (object $payment) use ($operations): array {
            $ops = $operations->get((int) $payment->id, collect());
            $captured = $this->sumOperation($ops, 'capture');
            $refunded = $this->sumOperation($ops, 'refund');
            $latestSettlement = $ops->filter(fn (object $op): bool => $op->settlement_gross_amount !== null)->last();
            $configured = $this->providers->configured((string) $payment->provider);
            $capabilities = [];
            if ($configured) {
                try {
                    $adapter = $this->providers->provider((string) $payment->provider);
                    foreach (self::OPERATIONS as $operation) {
                        $capabilities[$operation] = $adapter->supports($operation);
                    }
                } catch (InvalidArgumentException) {
                    $configured = false;
                }
            }

            return [
                ...((array) $payment),
                'captured_amount' => $this->formatMills($captured),
                'refunded_amount' => $this->formatMills($refunded),
                'remaining_refundable_amount' => $this->formatMills(max(0, $captured - $refunded)),
                'created_display' => CarbonImmutable::parse((string) $payment->created_at)
                    ->timezone('Asia/Kuwait')->format('Y-m-d H:i'),
                'settlement_gross_amount' => $latestSettlement?->settlement_gross_amount,
                'settlement_fee_amount' => $latestSettlement?->settlement_fee_amount,
                'settlement_net_amount' => $latestSettlement?->settlement_net_amount,
                'mismatch_code' => $latestSettlement?->mismatch_code,
                'provider_configured' => $configured,
                'capabilities' => $capabilities,
            ];
        })->all();

        return [$paginator, $rows];
    }

    private function assertRestaurantPayment(Payment $payment): void
    {
        abort_if($payment->order_id === null, 404);
        $exists = DB::table('smart_ordering_order_contexts')
            ->where('order_id', (int) $payment->order_id)
            ->exists();
        abort_unless($exists, 404);
    }

    private function assertOperationAllowed(Payment $payment, string $operation, ?string $amount): void
    {
        $status = strtolower((string) $payment->status);
        $allowed = match ($operation) {
            'authorize' => in_array($status, ['pending', 'failed'], true),
            'capture' => $status === 'authorized',
            'void' => in_array($status, ['pending', 'authorized'], true),
            'refund' => in_array($status, ['captured', 'partially_refunded'], true),
            default => false,
        };
        if (! $allowed) {
            throw ValidationException::withMessages([
                'operation' => ["Payment status [{$status}] does not allow {$operation}."],
            ]);
        }

        if (in_array($operation, ['authorize', 'capture'], true)
            && $amount !== null
            && $this->toMills($amount) > $this->toMills((string) $payment->amount)) {
            throw ValidationException::withMessages(['amount' => ['Payment amount cannot exceed the canonical payment amount.']]);
        }

        if ($operation === 'refund' && $amount !== null) {
            $captured = $this->successfulAmount((int) $payment->getKey(), 'capture');
            $refunded = $this->successfulAmount((int) $payment->getKey(), 'refund');
            if ($this->toMills($amount) > max(0, $captured - $refunded)) {
                throw ValidationException::withMessages(['amount' => ['Refund amount exceeds the remaining captured amount.']]);
            }
        }
    }

    private function statusAfterSuccess(Payment $payment, string $operation, ?string $amount): string
    {
        return match ($operation) {
            'authorize' => 'authorized',
            'capture' => 'captured',
            'void' => 'voided',
            'refund' => $this->refundStatus($payment),
            default => (string) $payment->status,
        };
    }

    private function refundStatus(Payment $payment): string
    {
        $captured = $this->successfulAmount((int) $payment->getKey(), 'capture');
        $refunded = $this->successfulAmount((int) $payment->getKey(), 'refund');

        return $captured > 0 && $refunded >= $captured ? 'refunded' : 'partially_refunded';
    }

    private function settlementMismatch(
        Payment $payment,
        ?string $gross,
        ?string $fee,
        ?string $net,
        string $operation,
        ?string $operationAmount,
    ): ?string {
        if ($gross === null && $fee === null && $net === null) {
            return null;
        }

        $captured = $this->successfulAmount((int) $payment->getKey(), 'capture');
        $refunded = $this->successfulAmount((int) $payment->getKey(), 'refund');
        if ($operation === 'capture' && $operationAmount !== null) {
            $captured += $this->toMills($operationAmount);
        } elseif ($operation === 'refund' && $operationAmount !== null) {
            $refunded += $this->toMills($operationAmount);
        }
        $expectedGross = max(0, $captured - $refunded);

        if ($gross !== null && $this->toMills($gross) !== $expectedGross) {
            return 'settlement_amount_mismatch';
        }

        if ($gross !== null && $fee !== null && $net !== null) {
            $expectedNet = max(0, $this->toMills($gross) - $this->toMills($fee));
            if ($this->toMills($net) !== $expectedNet) {
                return 'settlement_net_mismatch';
            }
        }

        return null;
    }

    private function successfulAmount(int $paymentId, string $operation): int
    {
        return DB::table('restaurant_payment_operations')
            ->where('payment_id', $paymentId)
            ->where('operation', $operation)
            ->where('status', 'succeeded')
            ->pluck('amount')
            ->reduce(fn (int $carry, mixed $value): int => $carry + $this->toMills((string) $value), 0);
    }

    /** @param Collection<int,object> $operations */
    private function sumOperation($operations, string $operation): int
    {
        return $operations
            ->filter(fn (object $row): bool => (string) $row->operation === $operation)
            ->reduce(fn (int $carry, object $row): int => $carry + $this->toMills((string) $row->amount), 0);
    }

    private function normalizeAmount(?string $amount, string $field): string
    {
        $amount = trim((string) $amount);
        if (! preg_match('/^\d{1,11}(?:\.\d{1,3})?$/', $amount) || $this->toMills($amount) < 1) {
            throw ValidationException::withMessages([$field => ['A positive amount with up to three decimal places is required.']]);
        }

        return $this->formatMills($this->toMills($amount));
    }

    private function nullableAmount(?string $amount): ?string
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        return $this->normalizeAmount($amount, 'provider_amount');
    }

    private function toMills(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if (preg_match('/(?:secret|token|credential|authorization|card|pan|cvv|cvc|raw|payload)/i', $key)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->safeMetadata($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return $safe;
    }

    /** @param array<string,mixed> $existing
     * @param  array<string,mixed>  $safeMetadata
     * @return array<string,mixed>
     */
    private function mergeSafePaymentMetadata(
        array $existing,
        string $operation,
        ?string $providerStatus,
        array $safeMetadata,
    ): array {
        unset(
            $existing['token'],
            $existing['secret'],
            $existing['card'],
            $existing['pan'],
            $existing['cvv'],
            $existing['cvc'],
        );
        $existing['restaurant_payment'] = [
            'last_operation' => $operation,
            'provider_status' => $this->trimNullable($providerStatus, 100),
            'safe' => $safeMetadata,
            'updated_at' => now()->toAtomString(),
        ];

        return $existing;
    }

    private function trimNullable(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
