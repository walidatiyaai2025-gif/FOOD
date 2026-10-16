<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_opening_balance_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('enterprise_fiscal_periods')->restrictOnDelete();
            $table->string('batch_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_debit', 18, 3);
            $table->decimal('total_credit', 18, 3);
            $table->foreignId('journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'batch_number'], 'efob_legal_number_unique');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'efob_legal_idempotency_unique');
            $table->unique('journal_id', 'efob_journal_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'entry_date', 'id'],
                'efob_scope_status_date_idx',
            );
            $table->index(
                ['fiscal_period_id', 'status', 'entry_date', 'id'],
                'efob_period_status_date_idx',
            );
        });

        Schema::create('enterprise_finance_opening_balance_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('enterprise_finance_opening_balance_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('enterprise_cost_centers')->restrictOnDelete();
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'line_number'], 'efobl_batch_line_unique');
            $table->index(['batch_id', 'account_id', 'id'], 'efobl_batch_account_idx');
            $table->index(['account_id', 'batch_id', 'id'], 'efobl_account_batch_idx');
            $table->index(['cost_center_id', 'batch_id', 'id'], 'efobl_cost_center_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_opening_balance_lines');
        Schema::dropIfExists('enterprise_finance_opening_balance_batches');
    }
};
