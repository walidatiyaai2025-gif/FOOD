<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('enterprise_fiscal_periods')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->date('entry_date');
            $table->string('reference', 120)->nullable();
            $table->string('memo', 1000)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'idempotency_key'], 'efj_legal_idempotency_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'entry_date', 'id'],
                'efj_scope_status_date_idx',
            );
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'entry_date', 'id'],
                'efj_scope_date_idx',
            );
            $table->index(['fiscal_period_id', 'status', 'id'], 'efj_period_status_idx');
        });

        Schema::create('enterprise_finance_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->unique(['journal_id', 'line_no'], 'efjl_journal_line_unique');
            $table->index(['account_id', 'journal_id'], 'efjl_account_journal_idx');
        });

        Schema::create('enterprise_finance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('journal_id')->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('journal_line_id')->constrained('enterprise_finance_journal_lines')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('enterprise_fiscal_periods')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->date('entry_date');
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('journal_line_id', 'efle_journal_line_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'entry_date', 'id'],
                'efle_scope_date_idx',
            );
            $table->index(
                ['legal_entity_id', 'account_id', 'entry_date', 'id'],
                'efle_account_date_idx',
            );
            $table->index(['journal_id', 'id'], 'efle_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_ledger_entries');
        Schema::dropIfExists('enterprise_finance_journal_lines');
        Schema::dropIfExists('enterprise_finance_journals');
    }
};
