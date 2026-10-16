<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_opening_balance_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('batch_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_debit', 18, 3);
            $table->decimal('total_credit', 18, 3);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'efob_org_fk')->references('id')->on('enterprise_organizations')->restrictOnDelete();
            $table->foreign('company_id', 'efob_company_fk')->references('id')->on('enterprise_companies')->restrictOnDelete();
            $table->foreign('legal_entity_id', 'efob_legal_fk')->references('id')->on('enterprise_legal_entities')->restrictOnDelete();
            $table->foreign('fiscal_period_id', 'efob_period_fk')->references('id')->on('enterprise_fiscal_periods')->restrictOnDelete();
            $table->foreign('journal_id', 'efob_journal_fk')->references('id')->on('enterprise_finance_journals')->restrictOnDelete();
            $table->foreign('created_by', 'efob_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('posted_by', 'efob_posted_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'efob_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();

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
            $table->unsignedBigInteger('batch_id');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->foreign('batch_id', 'efobl_batch_fk')->references('id')->on('enterprise_finance_opening_balance_batches')->cascadeOnDelete();
            $table->foreign('account_id', 'efobl_account_fk')->references('id')->on('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreign('cost_center_id', 'efobl_cost_center_fk')->references('id')->on('enterprise_cost_centers')->restrictOnDelete();

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
