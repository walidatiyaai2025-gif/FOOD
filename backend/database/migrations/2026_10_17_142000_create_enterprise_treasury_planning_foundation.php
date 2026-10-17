<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_treasury_facilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('lender_name', 190);
            $table->char('currency_code', 3);
            $table->decimal('facility_limit', 18, 3);
            $table->decimal('annual_rate', 9, 6)->default(0);
            $table->decimal('margin_rate', 9, 6)->default(0);
            $table->date('start_date');
            $table->date('maturity_date');
            $table->string('status', 20)->default('active');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('finance_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'etf_legal_code_uq');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'etf_legal_idem_uq');
            $table->index(['store_id', 'status', 'maturity_date', 'id'], 'etf_store_status_maturity_idx');
        });

        Schema::create('enterprise_treasury_drawdowns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('enterprise_treasury_facilities')->restrictOnDelete();
            $table->string('reference', 80);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->decimal('principal_amount', 18, 3);
            $table->decimal('scheduled_interest_total', 18, 3)->default(0);
            $table->date('drawdown_date');
            $table->date('maturity_date');
            $table->unsignedSmallInteger('installment_count');
            $table->string('status', 20)->default('active');
            $table->foreignId('finance_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'reference'], 'etd_facility_reference_uq');
            $table->unique(['facility_id', 'idempotency_key'], 'etd_facility_idem_uq');
            $table->index(['facility_id', 'status', 'maturity_date', 'id'], 'etd_facility_status_maturity_idx');
        });

        Schema::create('enterprise_treasury_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drawdown_id')->constrained('enterprise_treasury_drawdowns')->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('principal_amount', 18, 3);
            $table->decimal('interest_amount', 18, 3)->default(0);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('finance_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['drawdown_id', 'installment_number'], 'etr_drawdown_installment_uq');
            $table->index(['status', 'due_date', 'id'], 'etr_status_due_idx');
        });

        Schema::create('enterprise_treasury_investments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('institution', 190);
            $table->string('instrument_type', 100);
            $table->char('currency_code', 3);
            $table->decimal('principal_amount', 18, 3);
            $table->decimal('annual_yield_rate', 9, 6)->default(0);
            $table->decimal('expected_return_amount', 18, 3)->default(0);
            $table->date('placement_date');
            $table->date('maturity_date');
            $table->string('status', 20)->default('active');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('settlement_reference', 120)->nullable();
            $table->foreignId('finance_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'eti_legal_code_uq');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'eti_legal_idem_uq');
            $table->index(['store_id', 'status', 'maturity_date', 'id'], 'eti_store_status_maturity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_treasury_investments');
        Schema::dropIfExists('enterprise_treasury_repayments');
        Schema::dropIfExists('enterprise_treasury_drawdowns');
        Schema::dropIfExists('enterprise_treasury_facilities');
    }
};
