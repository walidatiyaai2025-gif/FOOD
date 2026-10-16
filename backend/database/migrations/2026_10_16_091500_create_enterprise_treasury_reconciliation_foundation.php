<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_treasury_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('enterprise_bank_accounts')->restrictOnDelete();
            $table->string('reconciliation_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('statement_receipts_total', 18, 3);
            $table->decimal('statement_payments_total', 18, 3);
            $table->string('currency_code', 3);
            $table->string('status', 16)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'reconciliation_number'], 'etr_rec_store_number_uq');
            $table->unique(['store_id', 'idempotency_key'], 'etr_rec_store_idem_uq');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'bank_account_id', 'status', 'period_to', 'id'],
                'etr_rec_scope_status_period_idx',
            );
        });

        Schema::create('enterprise_treasury_reconciliation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id')
                ->constrained('enterprise_treasury_reconciliations')
                ->cascadeOnDelete();
            $table->string('transaction_type', 16);
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('amount', 18, 3);
            $table->date('value_date');
            $table->string('reference', 180)->nullable();
            $table->timestamps();

            $table->unique(
                ['reconciliation_id', 'transaction_type', 'transaction_id'],
                'etr_rec_line_txn_uq',
            );
            $table->index(
                ['transaction_type', 'transaction_id', 'reconciliation_id'],
                'etr_rec_line_txn_idx',
            );
        });

        Schema::create('enterprise_treasury_cash_closings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('cashbox_id')->constrained('enterprise_cashboxes')->restrictOnDelete();
            $table->string('closing_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('business_date');
            $table->decimal('opening_amount', 18, 3);
            $table->decimal('receipt_total', 18, 3);
            $table->decimal('payment_total', 18, 3);
            $table->decimal('expected_closing_amount', 18, 3);
            $table->decimal('declared_closing_amount', 18, 3);
            $table->string('currency_code', 3);
            $table->string('status', 16)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'closing_number'], 'etc_close_store_number_uq');
            $table->unique(['store_id', 'idempotency_key'], 'etc_close_store_idem_uq');
            $table->unique(['cashbox_id', 'business_date'], 'etc_close_cashbox_date_uq');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'status', 'business_date', 'id'],
                'etc_close_scope_status_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_treasury_cash_closings');
        Schema::dropIfExists('enterprise_treasury_reconciliation_lines');
        Schema::dropIfExists('enterprise_treasury_reconciliations');
    }
};
