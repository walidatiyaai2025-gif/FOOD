<?php

namespace App\Services;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class B2bAccountLedgerService
{
    public const MANUAL_TYPES = [
        'opening_balance',
        'payment',
        'customer_credit',
        'credit_note',
        'debit_note',
        'return',
        'refund',
        'adjustment_positive',
        'adjustment_negative',
    ];

    /** @return array<string,mixed> */
    public function summary(
        B2bCustomer $customer,
        ?int $storeId = null,
        ?Collection $preloadedTransactions = null,
    ): array {
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->firstOrFail();

        $transactions = $preloadedTransactions ?? $this->transactions($customer, $storeId);
        $transactionCurrencies = $transactions
            ->pluck('currency')
            ->filter(fn (mixed $currency): bool => is_string($currency) && $currency !== '')
            ->unique()
            ->values();
        abort_if(
            $transactionCurrencies->count() > 1,
            409,
            'Account financial history contains mixed currencies and cannot be combined safely.',
        );

        $totalDebits = round((float) $transactions->sum('debit'), 3);
        $totalCredits = round((float) $transactions->sum('credit'), 3);
        // Business-facing convention (#893): positive means customer credit;
        // negative means the customer owes the company.
        $balance = round($totalCredits - $totalDebits, 3);
        $creditLimit = round((float) $account->credit_limit, 3);
        $outstandingReceivable = round(max(0 - $balance, 0), 3);
        $customerCreditBalance = round(max($balance, 0), 3);
        $availableCreditLine = round(max($creditLimit - $outstandingReceivable, 0), 3);
        $currency = $transactionCurrencies->first() ?? $this->currencyFor($customer, $storeId);
        [$openAmount, $overdueAmount] = $this->invoiceExposure($customer, $storeId);

        $lastPayment = $transactions
            ->filter(fn (array $row): bool => $row['type'] === 'payment' && (float) $row['credit'] > 0)
            ->sortByDesc('occurred_at')
            ->first();
        $lastTransaction = $transactions->sortByDesc('occurred_at')->first();

        return [
            'account_id' => (int) $account->getKey(),
            'customer_id' => (int) $customer->getKey(),
            'currency' => $currency,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'balance' => $balance,
            'balance_direction' => $balance > 0 ? 'company_owes_customer' : ($balance < 0 ? 'customer_owes_company' : 'settled'),
            'outstanding_receivable' => $outstandingReceivable,
            'customer_credit_balance' => $customerCreditBalance,
            'credit_limit' => $creditLimit,
            'available_credit_line' => $availableCreditLine,
            'purchasing_power' => round($availableCreditLine + $customerCreditBalance, 3),
            'open_amount' => $openAmount,
            'overdue_amount' => $overdueAmount,
            'last_payment' => $lastPayment,
            'last_transaction' => $lastTransaction,
        ];
    }

    /** @return array<string,mixed> */
    public function statement(
        B2bCustomer $customer,
        ?string $from = null,
        ?string $to = null,
        ?int $storeId = null,
    ): array {
        $all = $this->transactions($customer, $storeId)
            ->sortBy(fn (array $row): string => $row['occurred_at'].'|'.$row['sort_key'])
            ->values();

        $fromDate = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        $toDate = $to ? CarbonImmutable::parse($to)->endOfDay() : null;

        $openingBalance = 0.0;
        $period = collect();

        foreach ($all as $row) {
            $at = CarbonImmutable::parse($row['occurred_at']);
            $delta = (float) $row['credit'] - (float) $row['debit'];
            if ($fromDate !== null && $at->lt($fromDate)) {
                $openingBalance += $delta;

                continue;
            }
            if ($toDate !== null && $at->gt($toDate)) {
                continue;
            }
            $period->push($row);
        }

        $running = round($openingBalance, 3);
        $rows = $period->map(function (array $row) use (&$running): array {
            $running = round($running + (float) $row['credit'] - (float) $row['debit'], 3);
            $row['running_balance'] = $running;
            unset($row['sort_key']);

            return $row;
        })->values();

        $periodDebits = round((float) $rows->sum('debit'), 3);
        $periodCredits = round((float) $rows->sum('credit'), 3);
        $summary = $this->summary($customer, $storeId, $all);

        return [
            ...$summary,
            'from' => $from,
            'to' => $to,
            'opening_balance' => round($openingBalance, 3),
            'period_debits' => $periodDebits,
            'period_credits' => $periodCredits,
            'closing_balance' => round($openingBalance + $periodCredits - $periodDebits, 3),
            'transactions' => $rows->all(),
        ];
    }

    /** @param array<string,mixed> $data */
    public function appendManual(B2bCustomer $customer, array $data, User $actor): int
    {
        $type = (string) ($data['entry_type'] ?? '');
        if (in_array($type, self::MANUAL_TYPES, true) === false) {
            throw ValidationException::withMessages(['entry_type' => ['Unsupported ledger entry type.']]);
        }

        $debit = round(max(0, (float) ($data['debit'] ?? 0)), 3);
        $credit = round(max(0, (float) ($data['credit'] ?? 0)), 3);
        if (($debit > 0) === ($credit > 0)) {
            throw ValidationException::withMessages(['amount' => ['Exactly one of debit or credit must be greater than zero.']]);
        }

        $requiredDirection = match ($type) {
            'payment', 'customer_credit', 'credit_note', 'return', 'adjustment_negative' => 'credit',
            'debit_note', 'refund', 'adjustment_positive' => 'debit',
            default => null,
        };
        if ($requiredDirection === 'credit' && $credit <= 0) {
            throw ValidationException::withMessages(['amount' => ['This entry type must be recorded as a credit.']]);
        }
        if ($requiredDirection === 'debit' && $debit <= 0) {
            throw ValidationException::withMessages(['amount' => ['This entry type must be recorded as a debit.']]);
        }

        $currency = 'EGP';

        $storeId = isset($data['store_id']) && (int) $data['store_id'] > 0 ? (int) $data['store_id'] : null;

        $invoiceId = isset($data['invoice_id']) ? (int) $data['invoice_id'] : null;
        if ($invoiceId !== null && $invoiceId > 0) {
            $owned = DB::table('invoices')
                ->where('id', $invoiceId)
                ->where('b2b_customer_id', $customer->getKey())
                ->exists();
            abort_unless($owned, 404);
        }

        return (int) DB::table('customer_account_ledger_entries')->insertGetId([
            'b2b_customer_id' => (int) $customer->getKey(),
            'store_id' => $storeId,
            'invoice_id' => $invoiceId && $invoiceId > 0 ? $invoiceId : null,
            'order_id' => isset($data['order_id']) && (int) $data['order_id'] > 0 ? (int) $data['order_id'] : null,
            'entry_type' => $type,
            'reference' => isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $currency,
            'actor_user_id' => (int) $actor->getKey(),
            'source' => (string) ($data['source'] ?? 'dashboard_customer_360'),
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_THROW_ON_ERROR) : null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'created_at' => now(),
        ]);
    }

    /** @return array{ledger_entry_id:int,outstanding_before:float,outstanding_after:float,amount:float} */
    public function settleInvoice(
        B2bCustomer $customer,
        Invoice $invoice,
        float $amount,
        string $currency,
        User $actor,
        ?string $reference = null,
        ?string $description = null,
    ): array {
        $amount = round($amount, 3);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Settlement amount must be greater than zero.']]);
        }

        return DB::transaction(function () use ($customer, $invoice, $amount, $currency, $actor, $reference, $description): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            abort_unless((int) $locked->b2b_customer_id === (int) $customer->getKey(), 404);
            abort_if(in_array(strtolower((string) $locked->status), ['cancelled', 'canceled', 'void', 'voided'], true), 409, 'Cancelled or void invoices cannot be settled.');

            $amounts = $this->invoiceAmounts($locked);
            $outstanding = round((float) $amounts['outstanding_amount'], 3);
            if ($outstanding <= 0.0005) {
                throw ValidationException::withMessages(['amount' => ['Invoice is already fully settled.']]);
            }
            if ($amount - $outstanding > 0.0005) {
                throw ValidationException::withMessages(['amount' => ['Settlement amount cannot exceed the invoice outstanding amount.']]);
            }

            $entryId = $this->appendManual($customer, [
                'entry_type' => 'payment',
                'debit' => 0,
                'credit' => $amount,
                'currency' => strtoupper($currency),
                'reference' => $reference ?: ('SETTLE-'.$locked->invoice_number.'-'.now()->format('YmdHis')),
                'description' => $description ?: 'Invoice settlement',
                'invoice_id' => (int) $locked->getKey(),
                'order_id' => $locked->order_id === null ? null : (int) $locked->order_id,
                'store_id' => $locked->store_id === null ? null : (int) $locked->store_id,
                'occurred_at' => now(),
                'source' => 'dashboard_invoice_settlement',
                'metadata' => [
                    'invoice_number' => (string) $locked->invoice_number,
                    'outstanding_before' => $outstanding,
                ],
            ], $actor);

            $after = $this->invoiceAmounts($locked);

            return [
                'ledger_entry_id' => $entryId,
                'outstanding_before' => $outstanding,
                'outstanding_after' => round((float) $after['outstanding_amount'], 3),
                'amount' => $amount,
            ];
        }, 3);
    }

    /** @return array{ledger_entry_id:int,reversal_of:int} */
    public function reverseManualEntry(
        B2bCustomer $customer,
        int $ledgerEntryId,
        User $actor,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reversal reason is required.']]);
        }

        return DB::transaction(function () use ($customer, $ledgerEntryId, $actor, $reason): array {
            $entry = DB::table('customer_account_ledger_entries')
                ->where('id', $ledgerEntryId)
                ->where('b2b_customer_id', $customer->getKey())
                ->lockForUpdate()
                ->first();
            abort_unless($entry !== null, 404);

            $reversalReference = 'REVERSAL-'.$ledgerEntryId;
            $existing = DB::table('customer_account_ledger_entries')
                ->where('b2b_customer_id', $customer->getKey())
                ->where('source', 'ledger_reversal')
                ->where('reference', $reversalReference)
                ->first();
            if ($existing !== null) {
                return ['ledger_entry_id' => (int) $existing->id, 'reversal_of' => $ledgerEntryId];
            }

            $debit = round((float) $entry->credit, 3);
            $credit = round((float) $entry->debit, 3);
            abort_if(($debit > 0) === ($credit > 0), 409, 'Ledger entry cannot be reversed safely.');

            $newId = (int) DB::table('customer_account_ledger_entries')->insertGetId([
                'b2b_customer_id' => (int) $customer->getKey(),
                'store_id' => $entry->store_id,
                'invoice_id' => $entry->invoice_id,
                'order_id' => $entry->order_id,
                'entry_type' => $debit > 0 ? 'refund' : 'adjustment_negative',
                'reference' => $reversalReference,
                'description' => 'Reversal: '.$reason,
                'debit' => $debit,
                'credit' => $credit,
                'currency' => (string) $entry->currency,
                'actor_user_id' => (int) $actor->getKey(),
                'source' => 'ledger_reversal',
                'metadata' => json_encode([
                    'reversal_of' => $ledgerEntryId,
                    'original_type' => (string) $entry->entry_type,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['ledger_entry_id' => $newId, 'reversal_of' => $ledgerEntryId];
        }, 3);
    }

    /** @return array{invoice_total:float,paid_amount:float,debit_adjustments:float,credit_adjustments:float,outstanding_amount:float,credit_amount:float} */
    public function invoiceAmounts(Invoice $invoice): array
    {
        $paid = round((float) DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereIn('status', ['paid', 'captured'])
            ->sum('amount'), 3);

        $ledger = DB::table('customer_account_ledger_entries')
            ->where('invoice_id', $invoice->getKey())
            ->selectRaw('COALESCE(SUM(debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(credit), 0) as credits')
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN credit ELSE 0 END), 0) as payment_credits")
            ->first();

        $manualDebits = round((float) ($ledger->debits ?? 0), 3);
        $manualCredits = round((float) ($ledger->credits ?? 0), 3);
        $manualPayments = round((float) ($ledger->payment_credits ?? 0), 3);
        $invoiceTotal = round((float) $invoice->total, 3);
        $net = round($invoiceTotal + $manualDebits - $paid - $manualCredits, 3);

        return [
            'invoice_total' => $invoiceTotal,
            'paid_amount' => round($paid + $manualPayments, 3),
            'debit_adjustments' => $manualDebits,
            'credit_adjustments' => round(max($manualCredits - $manualPayments, 0), 3),
            'outstanding_amount' => round(max($net, 0), 3),
            'credit_amount' => round(max(0 - $net, 0), 3),
        ];
    }

    /** @return Collection<int,array{id:int,type:string,reference:mixed,description:mixed,debit:float,credit:float,currency:string,actor_user_id:int|null,source:string,occurred_at:string}> */
    public function invoiceLedgerEntries(Invoice $invoice): Collection
    {
        return DB::table('customer_account_ledger_entries')
            ->where('invoice_id', $invoice->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $entry): array => [
                'id' => (int) $entry->id,
                'type' => (string) $entry->entry_type,
                'reference' => $entry->reference,
                'description' => $entry->description,
                'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit,
                'currency' => (string) $entry->currency,
                'actor_user_id' => $entry->actor_user_id === null ? null : (int) $entry->actor_user_id,
                'source' => (string) $entry->source,
                'occurred_at' => (string) $entry->occurred_at,
            ]);
    }

    public function currencyFor(B2bCustomer $customer, ?int $storeId = null): ?string
    {
        return 'EGP';
    }

    /** @return Collection<int,array<string,mixed>> */
    private function transactions(B2bCustomer $customer, ?int $storeId): Collection
    {
        $rows = collect();

        $invoices = DB::table('invoices')
            ->where('b2b_customer_id', $customer->getKey())
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->whereNotIn('status', ['cancelled', 'void', 'voided'])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get(['id', 'store_id', 'order_id', 'invoice_number', 'currency', 'total', 'issued_at', 'created_at']);

        foreach ($invoices as $invoice) {
            $rows->push([
                'id' => 'invoice:'.$invoice->id,
                'type' => 'invoice',
                'reference' => (string) $invoice->invoice_number,
                'description' => 'Invoice '.$invoice->invoice_number,
                'debit' => round((float) $invoice->total, 3),
                'credit' => 0.0,
                'currency' => $this->normalizeCurrency($invoice->currency),
                'invoice_id' => (int) $invoice->id,
                'order_id' => $invoice->order_id === null ? null : (int) $invoice->order_id,
                'store_id' => $invoice->store_id === null ? null : (int) $invoice->store_id,
                'actor_user_id' => null,
                'source' => 'invoice',
                'occurred_at' => (string) ($invoice->issued_at ?? $invoice->created_at),
                'sort_key' => sprintf('1:%012d', $invoice->id),
            ]);
        }

        $invoiceIds = $invoices->pluck('id')->all();
        if ($invoiceIds !== []) {
            $payments = DB::table('payments')
                ->whereIn('invoice_id', $invoiceIds)
                ->whereIn('status', ['paid', 'captured'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'invoice_id', 'order_id', 'provider', 'provider_reference', 'currency', 'amount', 'created_at']);

            foreach ($payments as $payment) {
                $rows->push([
                    'id' => 'payment:'.$payment->id,
                    'type' => 'payment',
                    'reference' => $payment->provider_reference ?: ('PAY-'.$payment->id),
                    'description' => 'Payment via '.$payment->provider,
                    'debit' => 0.0,
                    'credit' => round((float) $payment->amount, 3),
                    'currency' => $this->normalizeCurrency($payment->currency),
                    'invoice_id' => $payment->invoice_id === null ? null : (int) $payment->invoice_id,
                    'order_id' => $payment->order_id === null ? null : (int) $payment->order_id,
                    'store_id' => null,
                    'actor_user_id' => null,
                    'source' => 'payment',
                    'occurred_at' => (string) $payment->created_at,
                    'sort_key' => sprintf('2:%012d', $payment->id),
                ]);
            }
        }

        $manual = DB::table('customer_account_ledger_entries')
            ->where('b2b_customer_id', $customer->getKey())
            ->when($storeId, fn ($q, int $id) => $q->where(function ($scope) use ($id): void {
                $scope->whereNull('store_id')->orWhere('store_id', $id);
            }))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($manual as $entry) {
            $rows->push([
                'id' => 'ledger:'.$entry->id,
                'type' => (string) $entry->entry_type,
                'reference' => $entry->reference,
                'description' => $entry->description,
                'debit' => round((float) $entry->debit, 3),
                'credit' => round((float) $entry->credit, 3),
                'currency' => $this->normalizeCurrency($entry->currency),
                'invoice_id' => $entry->invoice_id === null ? null : (int) $entry->invoice_id,
                'order_id' => $entry->order_id === null ? null : (int) $entry->order_id,
                'store_id' => $entry->store_id === null ? null : (int) $entry->store_id,
                'actor_user_id' => $entry->actor_user_id === null ? null : (int) $entry->actor_user_id,
                'source' => (string) $entry->source,
                'occurred_at' => (string) $entry->occurred_at,
                'sort_key' => sprintf('3:%012d', $entry->id),
            ]);
        }

        return $rows;
    }

    /** @return array{0:float,1:float} */
    private function invoiceExposure(B2bCustomer $customer, ?int $storeId): array
    {
        $invoices = DB::table('invoices')
            ->where('b2b_customer_id', $customer->getKey())
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->whereNotIn('status', ['cancelled', 'void', 'voided'])
            ->get(['id', 'total', 'due_at']);

        if ($invoices->isEmpty()) {
            return [0.0, 0.0];
        }

        $invoiceIds = $invoices->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $paidByInvoice = DB::table('payments')
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('status', ['paid', 'captured'])
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, COALESCE(SUM(amount), 0) as paid')
            ->pluck('paid', 'invoice_id');

        $ledgerByInvoice = DB::table('customer_account_ledger_entries')
            ->whereIn('invoice_id', $invoiceIds)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->get()
            ->keyBy('invoice_id');

        $open = 0.0;
        $overdue = 0.0;
        foreach ($invoices as $invoice) {
            $ledger = $ledgerByInvoice->get($invoice->id);
            $paid = (float) ($paidByInvoice[$invoice->id] ?? 0);
            $debits = (float) ($ledger->debits ?? 0);
            $credits = (float) ($ledger->credits ?? 0);
            $remaining = round(max((float) $invoice->total + $debits - $paid - $credits, 0), 3);

            $open += $remaining;
            if ($remaining > 0 && $invoice->due_at !== null && CarbonImmutable::parse($invoice->due_at)->isPast()) {
                $overdue += $remaining;
            }
        }

        return [round($open, 3), round($overdue, 3)];
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        if (is_string($value) === false) {
            return null;
        }
        $currency = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }
}
