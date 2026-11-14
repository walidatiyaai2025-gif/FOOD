<?php

namespace App\Services;

use App\Models\CollectionAccount;
use App\Models\CollectionTransaction;
use App\Models\CustodyLedgerEntry;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectionCustodyService
{
    /**
     * @param  list<array{invoice_id: int, amount: float|int|string|null}>  $allocations
     */
    public function collect(
        CollectionAccount $account,
        User $actor,
        string $idempotencyKey,
        float $amount,
        string $currency,
        string $source,
        array $allocations = [],
    ): CollectionTransaction {
        $currency = strtoupper(trim($currency));
        $this->assertAccountCurrency($account, $currency);

        // Non-finite float totals bypass <= 0 for NaN and can poison
        // payment, collection and custody ledger amounts.
        if (! is_finite($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Collection amount must be finite and greater than zero.']]);
        }

        return DB::transaction(function () use ($account, $actor, $idempotencyKey, $amount, $currency, $source, $allocations): CollectionTransaction {
            $existing = CollectionTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CollectionTransaction) {
                if ((int) $existing->collection_account_id !== (int) $account->getKey()) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency-Key is already owned by another collection account.'],
                    ]);
                }

                // A retry may return an earlier receipt only when every
                // financial term, including its invoice allocations, agrees.
                // Otherwise the caller would receive a false paid receipt for
                // a different amount, source or invoice.
                $this->assertCollectionReplayMatches($existing, $amount, $currency, $source, $allocations);

                return $existing;
            }

            // An invoice allocation is a financial write. Never accept an invoice by
            // numeric ID alone: the collector account, store and invoice channel must
            // share the same authoritative B2B/B2C scope before any payment is posted.
            $accountStoreId = (int) ($account->store_id ?? 0);
            $storeType = $allocations !== [] && $accountStoreId > 0
                ? DB::table('stores')
                    ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                    ->where('stores.id', $accountStoreId)
                    ->value('store_types.code')
                : null;
            $accountChannel = match (strtoupper(trim((string) $storeType))) {
                'B2B' => 'b2b',
                'B2C' => 'b2c',
                default => null,
            };

            if ($allocations !== [] && $accountChannel === null) {
                throw ValidationException::withMessages([
                    'allocations' => ['Collection account has no authoritative store/channel for invoice allocation.'],
                ]);
            }
            if ($allocations !== []
                && in_array(strtolower((string) $account->actor_type), ['van', 'van_rep'], true)
                && $accountChannel !== 'b2b') {
                throw ValidationException::withMessages([
                    'allocations' => ['Van collections can allocate only Wholesale invoices.'],
                ]);
            }
            // The inverse fulfillment boundary is equally authoritative:
            // a Driver cannot settle B2B invoices even if a malformed
            // collection account points at a legitimate Wholesale store.
            if ($allocations !== []
                && strtolower((string) $account->actor_type) === 'driver'
                && $accountChannel !== 'b2c') {
                throw ValidationException::withMessages([
                    'allocations' => ['Driver collections can allocate only Retail invoices.'],
                ]);
            }

            $allocatedTotal = 0.0;
            foreach ($allocations as $allocation) {
                // Never post zero, negative or non-finite invoice allocations:
                // a negative line can otherwise offset a positive line and
                // bypass the collected-amount cap before the payment is posted.
                $allocationAmount = $allocation['amount'] ?? null;
                if (! is_numeric($allocationAmount)
                    || ! is_finite((float) $allocationAmount)
                    || (float) $allocationAmount <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => ['Invoice allocation amount must be a finite value greater than zero.'],
                    ]);
                }

                $invoice = DB::table('invoices')->where('id', (int) $allocation['invoice_id'])->lockForUpdate()->first();
                if ($invoice === null) {
                    throw ValidationException::withMessages(['allocations' => ['Invoice not found.']]);
                }
                if ((int) ($invoice->store_id ?? 0) !== $accountStoreId
                    || strtolower(trim((string) ($invoice->channel ?? ''))) !== $accountChannel) {
                    throw ValidationException::withMessages([
                        'allocations' => ['Invoice does not belong to the collection account store and channel.'],
                    ]);
                }
                if (strtoupper((string) $invoice->currency) !== $currency) {
                    throw ValidationException::withMessages(['currency' => ['Mixed-currency collection allocation is not allowed.']]);
                }
                $allocatedTotal += (float) $allocationAmount;
            }

            if ($allocatedTotal > $amount + 0.0005) {
                throw ValidationException::withMessages(['allocations' => ['Allocated amount cannot exceed collected amount.']]);
            }

            $payment = Payment::query()->create([
                'invoice_id' => count($allocations) === 1 ? (int) $allocations[0]['invoice_id'] : null,
                'provider' => 'field_collection',
                'provider_reference' => $idempotencyKey,
                'status' => 'paid',
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => [
                    'source' => $source,
                    'collector_type' => (string) $account->actor_type,
                    'collector_id' => (int) $account->actor_id,
                ],
            ]);

            $transaction = CollectionTransaction::query()->create([
                'collection_account_id' => $account->getKey(),
                'payment_id' => $payment->getKey(),
                'idempotency_key' => $idempotencyKey,
                'type' => 'collection',
                'status' => 'posted',
                'amount' => $amount,
                'currency' => $currency,
                'source' => $source,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($allocations as $allocation) {
                DB::table('collection_allocations')->insert([
                    'collection_transaction_id' => $transaction->getKey(),
                    'invoice_id' => (int) $allocation['invoice_id'],
                    'amount' => (float) $allocation['amount'],
                    'currency' => $currency,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            CustodyLedgerEntry::query()->create([
                'collection_account_id' => $account->getKey(),
                'entry_type' => 'collection',
                'amount' => $amount,
                'currency' => $currency,
                'reference_type' => CollectionTransaction::class,
                'reference_id' => $transaction->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            return $transaction;
        });
    }

    public function submitRemittance(
        CollectionAccount $account,
        User $actor,
        string $idempotencyKey,
        float $amount,
        string $currency,
        string $method,
        ?string $reference = null,
        ?string $note = null,
    ): Remittance {
        $currency = strtoupper(trim($currency));
        $this->assertAccountCurrency($account, $currency);

        if (! is_finite($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Remittance amount must be finite and greater than zero.']]);
        }

        return DB::transaction(function () use ($account, $actor, $idempotencyKey, $amount, $currency, $method, $reference, $note): Remittance {
            $existing = Remittance::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Remittance) {
                if ((int) $existing->collection_account_id !== (int) $account->getKey()) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency-Key is already owned by another collection account.'],
                    ]);
                }

                // Reusing a key with different terms must never acknowledge
                // someone else's previously submitted treasury remittance.
                if (abs((float) $existing->amount - $amount) > 0.0005
                    || strtoupper((string) $existing->currency) !== $currency
                    || (string) $existing->method !== trim($method)
                    || $existing->reference !== $reference
                    || $existing->note !== $note) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency-Key already has different remittance terms.'],
                    ]);
                }

                return $existing;
            }

            if ($amount > $this->availableToRemit($account)) {
                throw ValidationException::withMessages(['amount' => ['Remittance exceeds available custody.']]);
            }

            return Remittance::query()->create([
                'collection_account_id' => $account->getKey(),
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => $currency,
                'method' => trim($method),
                'reference' => $reference,
                'status' => 'pending',
                'note' => $note,
                'submitted_by' => $actor->getKey(),
            ]);
        });
    }

    public function approveRemittance(Remittance $remittance, User $actor): Remittance
    {
        return DB::transaction(function () use ($remittance, $actor): Remittance {
            $locked = Remittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['Only pending remittances can be approved.']]);
            }

            $account = CollectionAccount::query()->whereKey($locked->collection_account_id)->lockForUpdate()->firstOrFail();
            $this->assertAccountCurrency($account, (string) $locked->currency);

            if ((float) $locked->amount > $this->custodyBalance($account)) {
                throw ValidationException::withMessages(['amount' => ['Custody is insufficient to approve this remittance.']]);
            }

            CustodyLedgerEntry::query()->create([
                'collection_account_id' => $account->getKey(),
                'entry_type' => 'approved_remittance',
                'amount' => -1 * (float) $locked->amount,
                'currency' => (string) $locked->currency,
                'reference_type' => Remittance::class,
                'reference_id' => $locked->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $locked->forceFill([
                'status' => 'approved',
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    public function rejectRemittance(Remittance $remittance, User $actor): Remittance
    {
        return DB::transaction(function () use ($remittance, $actor): Remittance {
            $locked = Remittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'rejected') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['Only pending remittances can be rejected.']]);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    public function reconcileRemittance(Remittance $remittance, User $actor): Remittance
    {
        return DB::transaction(function () use ($remittance, $actor): Remittance {
            $locked = Remittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'reconciled') {
                return $locked;
            }
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['status' => ['Only approved remittances can be reconciled.']]);
            }

            $account = CollectionAccount::query()->whereKey($locked->collection_account_id)->lockForUpdate()->firstOrFail();
            $this->assertAccountCurrency($account, (string) $locked->currency);

            $approvedEntryExists = CustodyLedgerEntry::query()
                ->where('collection_account_id', $account->getKey())
                ->where('entry_type', 'approved_remittance')
                ->where('reference_type', Remittance::class)
                ->where('reference_id', $locked->getKey())
                ->exists();

            if (! $approvedEntryExists) {
                throw ValidationException::withMessages([
                    'status' => ['Approved remittance custody evidence is missing and cannot be reconciled.'],
                ]);
            }

            $locked->forceFill([
                'status' => 'reconciled',
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    public function custodyBalance(CollectionAccount $account): float
    {
        return (float) CustodyLedgerEntry::query()
            ->where('collection_account_id', $account->getKey())
            ->where('currency', strtoupper((string) $account->currency))
            ->sum('amount');
    }

    public function availableToRemit(CollectionAccount $account): float
    {
        $pending = (float) Remittance::query()
            ->where('collection_account_id', $account->getKey())
            ->where('currency', strtoupper((string) $account->currency))
            ->where('status', 'pending')
            ->sum('amount');

        return max(0.0, $this->custodyBalance($account) - $pending);
    }

    /**
     * @param  list<array{invoice_id: int, amount: float|int|string|null}>  $allocations
     */
    private function assertCollectionReplayMatches(
        CollectionTransaction $existing,
        float $amount,
        string $currency,
        string $source,
        array $allocations,
    ): void {
        if (abs((float) $existing->amount - $amount) > 0.0005
            || strtoupper((string) $existing->currency) !== $currency
            || (string) $existing->source !== $source) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key already has different collection terms.'],
            ]);
        }

        $submitted = [];
        foreach ($allocations as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $lineAmount = $allocation['amount'] ?? null;
            if ($invoiceId <= 0
                || ! is_numeric($lineAmount)
                || ! is_finite((float) $lineAmount)
                || (float) $lineAmount <= 0
                || array_key_exists($invoiceId, $submitted)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency-Key replay has invalid invoice allocations.'],
                ]);
            }
            $submitted[$invoiceId] = (float) $lineAmount;
        }

        $persisted = DB::table('collection_allocations')
            ->where('collection_transaction_id', $existing->getKey())
            ->get(['invoice_id', 'amount']);

        if ($persisted->count() !== count($submitted)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key already has different invoice allocations.'],
            ]);
        }

        foreach ($persisted as $line) {
            $invoiceId = (int) $line->invoice_id;
            if (! array_key_exists($invoiceId, $submitted)
                || abs((float) $line->amount - $submitted[$invoiceId]) > 0.0005) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency-Key already has different invoice allocations.'],
                ]);
            }
        }
    }

    private function assertAccountCurrency(CollectionAccount $account, string $currency): void
    {
        if (strtoupper((string) $account->currency) !== strtoupper($currency)) {
            throw ValidationException::withMessages(['currency' => ['Collection account currency mismatch.']]);
        }
    }
}
