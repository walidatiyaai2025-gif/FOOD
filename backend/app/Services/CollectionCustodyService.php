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
     * @param list<array{invoice_id:int,amount:float|int}> $allocations
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

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Collection amount must be greater than zero.']]);
        }

        return DB::transaction(function () use ($account, $actor, $idempotencyKey, $amount, $currency, $source, $allocations): CollectionTransaction {
            $existing = CollectionTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CollectionTransaction) {
                return $existing;
            }

            $allocatedTotal = 0.0;
            foreach ($allocations as $allocation) {
                $invoice = DB::table('invoices')->where('id', (int) $allocation['invoice_id'])->lockForUpdate()->first();
                if ($invoice === null) {
                    throw ValidationException::withMessages(['allocations' => ['Invoice not found.']]);
                }
                if (strtoupper((string) $invoice->currency) !== $currency) {
                    throw ValidationException::withMessages(['currency' => ['Mixed-currency collection allocation is not allowed.']]);
                }
                $allocatedTotal += (float) $allocation['amount'];
            }

            if ($allocatedTotal > $amount + 0.0005) {
                throw ValidationException::withMessages(['allocations' => ['Allocated amount cannot exceed collected amount.']]);
            }

            $payment = Payment::query()->create([
                'invoice_id' => count($allocations) === 1 ? (int) $allocations[0]['invoice_id'] : null,
                'provider' => 'field_collection',
                'provider_reference' => $idempotencyKey,
                'status' => 'captured',
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

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Remittance amount must be greater than zero.']]);
        }

        return DB::transaction(function () use ($account, $actor, $idempotencyKey, $amount, $currency, $method, $reference, $note): Remittance {
            $existing = Remittance::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Remittance) {
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

    private function assertAccountCurrency(CollectionAccount $account, string $currency): void
    {
        if (strtoupper((string) $account->currency) !== strtoupper($currency)) {
            throw ValidationException::withMessages(['currency' => ['Collection account currency mismatch.']]);
        }
    }
}
