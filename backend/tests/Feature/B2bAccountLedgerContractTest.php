<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\CollectionAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\B2bAccountLedgerService;
use App\Services\CollectionCustodyService;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class B2bAccountLedgerContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_signed_balance_credit_limit_and_customer_credit_use_one_authoritative_contract(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 80, now()->subDay());
        $this->payment($invoice->id, 30);

        $ledger = app(B2bAccountLedgerService::class);
        $summary = $ledger->summary($customer, $storeId);

        $this->assertSame('EGP', $summary['currency']);
        $this->assertSame(80.0, $summary['total_debits']);
        $this->assertSame(30.0, $summary['total_credits']);
        $this->assertSame(-50.0, $summary['balance']);
        $this->assertSame('customer_owes_company', $summary['balance_direction']);
        $this->assertSame(50.0, $summary['outstanding_receivable']);
        $this->assertSame(0.0, $summary['customer_credit_balance']);
        $this->assertSame(100.0, $summary['credit_limit']);
        $this->assertSame(50.0, $summary['available_credit_line']);
        $this->assertSame(50.0, $summary['purchasing_power']);
        $this->assertSame(50.0, $summary['open_amount']);
        $this->assertSame(50.0, $summary['overdue_amount']);

        $ledger->appendManual($customer, [
            'entry_type' => 'payment',
            'credit' => 70,
            'debit' => 0,
            'currency' => 'EGP',
            'reference' => 'OVERPAY-1',
            'description' => 'Customer overpayment',
        ], $user);

        $credit = $ledger->summary($customer, $storeId);
        $this->assertSame(20.0, $credit['balance']);
        $this->assertSame('company_owes_customer', $credit['balance_direction']);
        $this->assertSame(0.0, $credit['outstanding_receivable']);
        $this->assertSame(20.0, $credit['customer_credit_balance']);
        $this->assertSame(100.0, $credit['available_credit_line']);
        $this->assertSame(120.0, $credit['purchasing_power']);

        $ledger->appendManual($customer, [
            'entry_type' => 'refund',
            'debit' => 10,
            'credit' => 0,
            'currency' => 'EGP',
            'reference' => 'REFUND-1',
            'description' => 'Refund reduces customer credit',
        ], $user);

        $afterRefund = $ledger->summary($customer, $storeId);
        $this->assertSame(10.0, $afterRefund['balance']);
        $this->assertSame(10.0, $afterRefund['customer_credit_balance']);
        $this->assertSame(110.0, $afterRefund['purchasing_power']);

        $statement = $ledger->statement($customer, null, null, $storeId);
        $this->assertSame(90.0, $statement['period_debits']);
        $this->assertSame(100.0, $statement['period_credits']);
        $this->assertSame(10.0, $statement['closing_balance']);
        $this->assertSame(10.0, $statement['transactions'][count($statement['transactions']) - 1]['running_balance']);

        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'b2b_customer_id' => $customer->id,
            'entry_type' => 'payment',
            'reference' => 'OVERPAY-1',
            'actor_user_id' => $user->id,
            'source' => 'dashboard_customer_360',
        ]);
    }

    public function test_account_summary_and_statement_apis_share_currency_balance_and_running_balance(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(75);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->subDays(3));
        $this->payment($invoice->id, 10);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/account-summary?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.balance', -50)
            ->assertJsonPath('data.outstanding_receivable', 50)
            ->assertJsonPath('data.customer_credit_balance', 0)
            ->assertJsonPath('data.credit_limit', 75)
            ->assertJsonPath('data.available_credit_line', 25)
            ->assertJsonPath('data.purchasing_power', 25);

        $this->getJson('/api/v1/b2b/account-statement?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.total_debits', 60)
            ->assertJsonPath('data.total_credits', 10)
            ->assertJsonPath('data.balance', -50)
            ->assertJsonPath('data.closing_balance', -50)
            ->assertJsonPath('data.transactions.0.type', 'invoice')
            ->assertJsonPath('data.transactions.0.running_balance', -60)
            ->assertJsonPath('data.transactions.1.type', 'payment')
            ->assertJsonPath('data.transactions.1.running_balance', -50);

        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.account_summary_viewed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.statement_viewed']);
    }

    public function test_statement_filters_paginate_and_export_the_same_authoritative_period(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->addWeek());
        $this->payment($invoice->id, 10);

        Sanctum::actingAs($user);
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();
        $query = '?store_id='.$storeId.'&from='.$from.'&to='.$to;

        $this->getJson('/api/v1/b2b/account-statement'.$query.'&page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.opening_balance', -60)
            ->assertJsonPath('data.period_debits', 0)
            ->assertJsonPath('data.period_credits', 10)
            ->assertJsonPath('data.closing_balance', -50)
            ->assertJsonPath('data.transactions.0.type', 'payment')
            ->assertJsonPath('data.transactions.0.running_balance', -50)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.last_page', 1);

        $xlsx = $this->get('/api/v1/b2b/account-statement/export'.$query.'&format=xlsx&locale=en');
        $xlsx->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->get('/api/v1/b2b/account-statement/export'.$query.'&format=pdf&locale=ar');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.statement_exported']);
    }

    public function test_invoice_outstanding_reconciles_allocated_ledger_payments_and_credit_notes(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->addWeek());
        $this->payment($invoice->id, 10);

        $ledger = app(B2bAccountLedgerService::class);
        $ledger->appendManual($customer, [
            'entry_type' => 'payment',
            'credit' => 15,
            'debit' => 0,
            'currency' => 'EGP',
            'invoice_id' => $invoice->id,
            'reference' => 'ALLOC-PAY-15',
        ], $user);
        $ledger->appendManual($customer, [
            'entry_type' => 'credit_note',
            'credit' => 5,
            'debit' => 0,
            'currency' => 'EGP',
            'invoice_id' => $invoice->id,
            'reference' => 'CN-5',
        ], $user);

        $amounts = $ledger->invoiceAmounts($invoice);
        $this->assertSame(60.0, $amounts['invoice_total']);
        $this->assertSame(25.0, $amounts['paid_amount']);
        $this->assertSame(5.0, $amounts['credit_adjustments']);
        $this->assertSame(30.0, $amounts['outstanding_amount']);
        $this->assertSame(0.0, $amounts['credit_amount']);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 25)
            ->assertJsonPath('data.credit_adjustments', 5)
            ->assertJsonPath('data.outstanding_amount', 30)
            ->assertJsonPath('data.ledger_entries.0.type', 'payment')
            ->assertJsonPath('data.ledger_entries.1.type', 'credit_note');

        $summary = $ledger->summary($customer, $storeId);
        $this->assertSame(-30.0, $summary['balance']);
        $this->assertSame(30.0, $summary['open_amount']);
    }

    public function test_captured_field_collection_reduces_authoritative_invoice_outstanding(): void
    {
        [, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->addWeek());

        DB::table('payments')->insert([
            'invoice_id' => $invoice->id,
            'provider' => 'field_collection',
            'provider_reference' => 'FIELD-COLLECTION-25',
            'status' => 'captured',
            'amount' => 25,
            'currency' => 'EGP',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $ledger = app(B2bAccountLedgerService::class);
        $amounts = $ledger->invoiceAmounts($invoice);

        $this->assertSame(25.0, $amounts['paid_amount']);
        $this->assertSame(35.0, $amounts['outstanding_amount']);

        $summary = $ledger->summary($customer, $storeId);
        $this->assertSame(25.0, $summary['total_credits']);
        $this->assertSame(-35.0, $summary['balance']);
        $this->assertSame(35.0, $summary['open_amount']);

        $statement = $ledger->statement($customer, null, null, $storeId);
        $this->assertSame('payment', $statement['transactions'][1]['type']);
        $this->assertSame(25.0, $statement['transactions'][1]['credit']);
    }

    public function test_invoice_api_exposes_paid_and_captured_field_payments_with_ledger_parity_without_finalizing_partial_invoice(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 80, now()->addWeek());

        $paidId = DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'provider' => 'account',
            'provider_reference' => 'P303-READBACK-PAID-20',
            'status' => 'paid',
            'amount' => 20,
            'currency' => 'EGP',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $capturedId = DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'provider' => 'field_collection',
            'provider_reference' => 'P303-READBACK-CAPTURED-10',
            'status' => 'captured',
            'amount' => 10,
            'currency' => 'EGP',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id.'?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.display_status', 'partially_paid')
            ->assertJsonPath('data.total', 80)
            ->assertJsonPath('data.paid_amount', 30)
            ->assertJsonPath('data.outstanding_amount', 50)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.id', $paidId)
            ->assertJsonPath('data.payments.0.status', 'paid')
            ->assertJsonPath('data.payments.0.amount', 20)
            ->assertJsonPath('data.payments.1.id', $capturedId)
            ->assertJsonPath('data.payments.1.status', 'captured')
            ->assertJsonPath('data.payments.1.method', 'field_collection')
            ->assertJsonPath('data.payments.1.reference', 'P303-READBACK-CAPTURED-10')
            ->assertJsonPath('data.payments.1.currency', 'EGP')
            ->assertJsonPath('data.payments.1.amount', 10);

        $this->getJson('/api/v1/b2b/account-summary?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.total_credits', 30)
            ->assertJsonPath('data.outstanding_receivable', 50);

        $this->getJson('/api/v1/b2b/account-statement?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.total_credits', 30)
            ->assertJsonPath('data.closing_balance', -50);

        // A forged scope must not expose even an otherwise owned invoice.
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id.'?store_id='.($storeId + 9999))
            ->assertNotFound();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', ['id' => $capturedId, 'status' => 'captured']);
    }

    public function test_real_van_multi_invoice_collection_allocates_exact_mills_once_in_credit_statement_and_customer_api(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(200);
        $first = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 80, now()->addWeek());
        $second = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 50, now()->addWeek());

        $wallet = CollectionAccount::query()->create([
            'actor_type' => 'van_rep',
            'actor_id' => $user->getKey(),
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
        ]);
        $custody = app(CollectionCustodyService::class);
        $allocationRequest = [
            ['invoice_id' => $first->id, 'amount' => 30.001],
            ['invoice_id' => $second->id, 'amount' => 20.000],
        ];
        $receipt = $custody->collect(
            $wallet, $user, 'p303-van-split-collection-1', 50.001, 'EGP', 'van_app',
            $allocationRequest,
        );
        $replay = $custody->collect(
            $wallet, $user, 'p303-van-split-collection-1', 50.001, 'EGP', 'van_app',
            $allocationRequest,
        );
        $this->assertSame($receipt->id, $replay->id);
        $this->assertDatabaseHas('payments', ['id' => $receipt->payment_id, 'invoice_id' => null]);
        $this->assertSame(2, DB::table('collection_allocations')
            ->where('collection_transaction_id', $receipt->id)->count());

        $ledger = app(B2bAccountLedgerService::class);
        $this->assertSame(30.001, $ledger->invoiceAmounts($first)['paid_amount']);
        $this->assertSame(49.999, $ledger->invoiceAmounts($first)['outstanding_amount']);
        $this->assertSame(20.0, $ledger->invoiceAmounts($second)['paid_amount']);
        $this->assertSame(30.0, $ledger->invoiceAmounts($second)['outstanding_amount']);
        $summary = $ledger->summary($customer, $storeId);
        $this->assertSame(50.001, $summary['total_credits']);
        $this->assertSame(-79.999, $summary['balance']);
        $this->assertSame(79.999, $summary['open_amount']);
        $statement = $ledger->statement($customer, null, null, $storeId);
        $splitRows = collect($statement['transactions'])
            ->where('source', 'field_collection_allocation')
            ->values();
        $this->assertCount(2, $splitRows);
        $this->assertSame(30.001, $splitRows[0]['credit']);
        $this->assertSame(20.0, $splitRows[1]['credit']);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/b2b/invoices/'.$first->id.'?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 30.001)
            ->assertJsonPath('data.outstanding_amount', 49.999)
            ->assertJsonPath('data.payments.0.id', $receipt->payment_id)
            ->assertJsonPath('data.payments.0.amount', 30.001);
        $this->getJson('/api/v1/b2b/invoices/'.$second->id.'?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 20)
            ->assertJsonPath('data.outstanding_amount', 30)
            ->assertJsonPath('data.payments.0.id', $receipt->payment_id)
            ->assertJsonPath('data.payments.0.amount', 20);
        $this->getJson('/api/v1/b2b/account-summary?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.total_credits', 50.001)
            ->assertJsonPath('data.outstanding_receivable', 79.999);
        $this->getJson('/api/v1/b2b/invoices/'.$first->id.'?store_id='.($storeId + 10000))
            ->assertNotFound();

        // The entire receipt becomes invalid if even one allocation's
        // persisted currency is forged; the bad line must not count.
        $sourceId = DB::table('collection_allocations')
            ->where('collection_transaction_id', $receipt->id)
            ->where('invoice_id', $first->id)
            ->value('id');
        DB::table('collection_allocations')->where('id', $sourceId)
            ->update(['currency' => 'KWD']);
        $this->assertSame(0.0, $ledger->invoiceAmounts($first)['paid_amount']);
        $this->assertSame(20.0, $ledger->invoiceAmounts($second)['paid_amount']);
        DB::table('collection_allocations')->where('id', $sourceId)
            ->update(['currency' => 'EGP']);

        // The fix for binary SUM drift must not relax the 3-decimal source
        // cap. A single mill of over-allocation voids the whole receipt.
        $otherSourceId = DB::table('collection_allocations')
            ->where('collection_transaction_id', $receipt->id)
            ->where('invoice_id', $second->id)
            ->value('id');
        DB::table('collection_allocations')->where('id', $otherSourceId)
            ->update(['amount' => '20.001']);
        $this->assertSame(0.0, $ledger->invoiceAmounts($first)['paid_amount']);
        $this->assertSame(0.0, $ledger->invoiceAmounts($second)['paid_amount']);
        $this->assertSame(0.0, $ledger->summary($customer, $storeId)['total_credits']);
        DB::table('collection_allocations')->where('id', $otherSourceId)
            ->update(['amount' => '20.000']);
        $this->assertSame(30.001, $ledger->invoiceAmounts($first)['paid_amount']);
        $this->assertSame(20.0, $ledger->invoiceAmounts($second)['paid_amount']);

        // A posted reversal must remove the original field receipt from
        // readback without producing a second payment or a Finance journal.
        DB::table('collection_transactions')->insert([
            'collection_account_id' => $wallet->id,
            'payment_id' => null,
            'idempotency_key' => 'p303-van-split-reversal-1',
            'type' => 'reversal',
            'status' => 'posted',
            'amount' => '-50.001',
            'currency' => 'EGP',
            'source' => 'van_app',
            'reversal_of_id' => $receipt->id,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0.0, $ledger->invoiceAmounts($first)['paid_amount']);
        $this->assertSame(0.0, $ledger->invoiceAmounts($second)['paid_amount']);
        $this->assertSame(0.0, $ledger->summary($customer, $storeId)['total_credits']);
        $this->assertSame(1, DB::table('payments')->where('id', $receipt->payment_id)->count());
    }

    public function test_opening_notes_returns_and_adjustments_are_append_only_supported_types(): void
    {
        [$user, , $customer] = $this->account(200);
        $ledger = app(B2bAccountLedgerService::class);

        foreach ([
            ['opening_balance', 'debit', 20],
            ['credit_note', 'credit', 4],
            ['debit_note', 'debit', 2],
            ['return', 'credit', 3],
            ['adjustment_positive', 'debit', 1],
            ['adjustment_negative', 'credit', 2],
        ] as [$type, $direction, $amount]) {
            $ledger->appendManual($customer, [
                'entry_type' => $type,
                'debit' => $direction === 'debit' ? $amount : 0,
                'credit' => $direction === 'credit' ? $amount : 0,
                'currency' => 'EGP',
                'reference' => strtoupper($type),
            ], $user);
        }

        $summary = $ledger->summary($customer);
        $this->assertSame(23.0, $summary['total_debits']);
        $this->assertSame(9.0, $summary['total_credits']);
        $this->assertSame(-14.0, $summary['balance']);
        $this->assertSame(6, DB::table('customer_account_ledger_entries')->where('b2b_customer_id', $customer->id)->count());
        $this->assertFalse(Schema::hasColumn('customer_account_ledger_entries', 'updated_at'));
    }

    public function test_customer_credit_entry_uses_positive_business_balance_and_append_only_ledger(): void
    {
        [$user, , $customer, $storeId] = $this->account(100);
        $ledger = app(B2bAccountLedgerService::class);

        $ledger->appendManual($customer, [
            'entry_type' => 'customer_credit',
            'credit' => 50,
            'debit' => 0,
            'currency' => 'EGP',
            'store_id' => $storeId,
            'reference' => 'CUSTOMER-CREDIT-50',
            'description' => 'Operator-added customer credit',
        ], $user);

        $summary = $ledger->summary($customer, $storeId);

        $this->assertSame(50.0, $summary['balance']);
        $this->assertSame('company_owes_customer', $summary['balance_direction']);
        $this->assertSame(0.0, $summary['outstanding_receivable']);
        $this->assertSame(50.0, $summary['customer_credit_balance']);
        $this->assertSame(150.0, $summary['purchasing_power']);
        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'b2b_customer_id' => $customer->id,
            'entry_type' => 'customer_credit',
            'credit' => 50,
            'debit' => 0,
            'reference' => 'CUSTOMER-CREDIT-50',
        ]);
    }

    public function test_invoice_settlement_is_ledger_authoritative_and_rejects_overpayment(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->subDay());
        $ledger = app(B2bAccountLedgerService::class);

        $partial = $ledger->settleInvoice($customer, $invoice, 25, 'EGP', $user, 'SETTLE-25');
        $this->assertSame(60.0, $partial['outstanding_before']);
        $this->assertSame(35.0, $partial['outstanding_after']);
        $this->assertSame(25.0, $ledger->invoiceAmounts($invoice)['paid_amount']);
        $this->assertSame(-35.0, $ledger->summary($customer, $storeId)['balance']);

        $full = $ledger->settleInvoice($customer, $invoice, 35, 'EGP', $user, 'SETTLE-35');
        $this->assertSame(0.0, $full['outstanding_after']);
        $this->assertSame(60.0, $ledger->invoiceAmounts($invoice)['paid_amount']);
        $this->assertSame(0.0, $ledger->summary($customer, $storeId)['balance']);

        $this->expectException(ValidationException::class);
        $ledger->settleInvoice($customer, $invoice, 1, 'EGP', $user, 'SETTLE-OVER');
    }

    public function test_invoice_settlement_retries_are_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 80, now()->subDay());
        $other = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 40, now()->subDay());
        $ledger = app(B2bAccountLedgerService::class);

        $key = '71817375-12ea-48ab-8982-316ff128a555';
        $one = $ledger->settleInvoice($customer, $invoice, 30, 'EGP', $user, 'RETRY-A', null, $key);
        $again = $ledger->settleInvoice($customer, $invoice, 30, 'EGP', $user, 'RETRY-A', null, $key);
        $this->assertSame($one['ledger_entry_id'], $again['ledger_entry_id']);
        $this->assertFalse($one['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame(50.0, $ledger->invoiceAmounts($invoice)['outstanding_amount']);
        $this->assertSame(1, DB::table('customer_account_ledger_entries')
            ->where('settlement_request_id', $key)->count());

        foreach ([[$invoice, 29.0], [$other, 30.0]] as [$wrongInvoice, $wrongAmount]) {
            try {
                $ledger->settleInvoice($customer, $wrongInvoice, $wrongAmount, 'EGP', $user, 'DIFFERENT', null, $key);
                $this->fail('Reusing the request ID for a different transaction must be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('settlement_request_id', $e->errors());
            }
        }
        $this->assertSame(1, DB::table('customer_account_ledger_entries')->count());

        $this->app->setLocale('ar');
        try {
            $ledger->settleInvoice($customer, $other, 30, 'EGP', $user, null, null, $key);
            $this->fail('The same key must not switch invoices in Arabic either.');
        } catch (ValidationException $e) {
            $this->assertSame(__('invoice_errors.settlement_request_id_conflict'), $e->errors()['settlement_request_id'][0]);
            $this->assertStringContainsString('مُعرّف', $e->errors()['settlement_request_id'][0]);
        }
        $this->app->setLocale('en');
        $this->assertSame(1, DB::table('customer_account_ledger_entries')->count());

        $fullKey = 'dd672165-7a5c-41e2-b131-287ee7920ba7';
        $full = $ledger->settleInvoice($customer, $invoice, 50, 'EGP', $user, 'RETRY-B', null, $fullKey);
        $this->assertSame(0.0, $full['outstanding_after']);
        $fullReplay = $ledger->settleInvoice($customer, $invoice, 50, 'EGP', $user, 'RETRY-B', null, $fullKey);
        $this->assertTrue($fullReplay['replayed']);
        $this->assertSame($full['ledger_entry_id'], $fullReplay['ledger_entry_id']);
        $this->assertSame(2, DB::table('customer_account_ledger_entries')->count());
        $this->assertSame(80.0, $ledger->invoiceAmounts($invoice)['paid_amount']);
    }

    public function test_invalid_settlement_request_key_never_writes_to_ledger(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->subDay());

        try {
            app(B2bAccountLedgerService::class)
                ->settleInvoice($customer, $invoice, 20, 'EGP', $user, null, null, '../forged');
            $this->fail('Malformed request identity must fail closed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settlement_request_id', $e->errors());
        }

        $this->assertSame(0, DB::table('customer_account_ledger_entries')->count());
    }

    public function test_reversal_is_append_only_idempotent_and_restores_invoice_outstanding(): void
    {
        [$user, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $invoice = $this->invoice($legacyCustomer->id, $customer->id, $storeId, 60, now()->subDay());
        $ledger = app(B2bAccountLedgerService::class);

        $settlement = $ledger->settleInvoice($customer, $invoice, 20, 'EGP', $user, 'SETTLE-REV');
        $beforeCount = DB::table('customer_account_ledger_entries')->count();

        $reversal = $ledger->reverseManualEntry(
            $customer,
            (int) $settlement['ledger_entry_id'],
            $user,
            'Operator corrected the settlement',
        );
        $same = $ledger->reverseManualEntry(
            $customer,
            (int) $settlement['ledger_entry_id'],
            $user,
            'Repeated request',
        );

        $this->assertSame($reversal['ledger_entry_id'], $same['ledger_entry_id']);
        $this->assertSame($beforeCount + 1, DB::table('customer_account_ledger_entries')->count());
        $this->assertSame(60.0, $ledger->invoiceAmounts($invoice)['outstanding_amount']);
        $this->assertSame(-60.0, $ledger->summary($customer, $storeId)['balance']);
        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'id' => $reversal['ledger_entry_id'],
            'source' => 'ledger_reversal',
            'reference' => 'REVERSAL-'.$settlement['ledger_entry_id'],
            'debit' => 20,
            'credit' => 0,
        ]);
    }

    /** @return array{0:User,1:Customer,2:B2bCustomer,3:int} */
    private function account(float $creditLimit): array
    {
        $user = User::query()->create([
            'name' => 'Ledger Buyer',
            'email' => uniqid('ledger-', true).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Ledger Buyer',
            'email' => $user->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacy->id,
            'company_name' => 'Ledger Co',
            'status' => 'active',
            'credit_limit' => $creditLimit,
        ]);

        $customer = app(CustomerDomainResolver::class)->b2b($user);
        $storeId = app(WholesalePrincipal::class)->storeId();

        return [$user, $legacy, $customer, $storeId];
    }

    private function invoice(
        int $legacyCustomerId,
        int $customerId,
        int $storeId,
        float $total,
        mixed $dueAt,
    ): Invoice {
        return Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $customerId,
            'invoice_number' => uniqid('LEDGER-INV-', true),
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => $total,
            'issued_at' => now()->subDays(5),
            'due_at' => $dueAt,
        ]);
    }

    private function payment(int $invoiceId, float $amount): void
    {
        DB::table('payments')->insert([
            'invoice_id' => $invoiceId,
            'provider' => 'account',
            'provider_reference' => uniqid('LEDGER-PAY-', true),
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'EGP',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
    }
}
