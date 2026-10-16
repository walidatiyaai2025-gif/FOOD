<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_ar_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('document_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('document_type', 24)->default('invoice');
            $table->date('entry_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 3);
            $table->string('currency_code', 3)->default('KWD');
            $table->foreignId('receivable_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('offset_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'document_number'], 'ear_doc_legal_number_uq');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'ear_doc_legal_idem_uq');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'due_date', 'id'],
                'ear_doc_scope_status_due_idx',
            );
            $table->index(
                ['legal_entity_id', 'customer_id', 'status', 'due_date', 'id'],
                'ear_doc_customer_status_due_idx',
            );
        });

        Schema::create('enterprise_ar_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ar_document_id')->constrained('enterprise_ar_documents')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('allocation_date');
            $table->decimal('amount', 18, 3);
            $table->foreignId('settlement_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->string('reference', 180)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ar_document_id', 'idempotency_key'], 'ear_alloc_doc_idem_uq');
            $table->index(
                ['ar_document_id', 'status', 'allocation_date', 'id'],
                'ear_alloc_doc_status_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_ar_allocations');
        Schema::dropIfExists('enterprise_ar_documents');
    }
};
