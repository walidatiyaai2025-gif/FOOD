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
            $table->string('journal_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_debit', 18, 3);
            $table->decimal('total_credit', 18, 3);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'journal_number'], 'efj_legal_number_unique');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'efj_legal_idempotency_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'entry_date', 'id'],
                'efj_scope_status_date_idx',
            );
            $table->index(
                ['fiscal_period_id', 'status', 'entry_date', 'id'],
                'efj_period_status_date_idx',
            );
        });

        Schema::create('enterprise_finance_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('enterprise_finance_journals')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->unique(['journal_id', 'line_number'], 'efjl_journal_line_unique');
            $table->index(['journal_id', 'account_id', 'id'], 'efjl_journal_account_idx');
            $table->index(['account_id', 'journal_id', 'id'], 'efjl_account_journal_idx');
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
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->unique('journal_line_id', 'efle_journal_line_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'entry_date', 'id'],
                'efle_scope_date_idx',
            );
            $table->index(['account_id', 'entry_date', 'id'], 'efle_account_date_idx');
            $table->index(['fiscal_period_id', 'entry_date', 'id'], 'efle_period_date_idx');
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
