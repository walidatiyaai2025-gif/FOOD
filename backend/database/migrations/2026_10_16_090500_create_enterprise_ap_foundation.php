<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_ap_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('supplier_reference', 128);
            $table->string('supplier_name', 190)->nullable();
            $table->string('document_number', 40);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('document_type', 24)->default('invoice');
            $table->date('entry_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 3);
            $table->char('currency_code', 3)->default('KWD');
            $table->foreignId('payable_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('offset_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['legal_entity_id', 'document_number'],
                'eap_doc_legal_number_uq',
            );
            $table->unique(
                ['legal_entity_id', 'idempotency_key'],
                'eap_doc_legal_idem_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'status', 'due_date', 'id'],
                'eap_doc_scope_status_due_idx',
            );
            $table->index(
                ['legal_entity_id', 'store_id', 'supplier_reference', 'status', 'due_date', 'id'],
                'eap_doc_supplier_status_due_idx',
            );
        });

        Schema::create('enterprise_ap_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ap_document_id')
                ->constrained('enterprise_ap_documents')
                ->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->date('allocation_date');
            $table->decimal('amount', 18, 3);
            $table->foreignId('settlement_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->string('reference', 180)->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['ap_document_id', 'idempotency_key'],
                'eap_alloc_doc_idem_uq',
            );
            $table->index(
                ['ap_document_id', 'status', 'allocation_date', 'id'],
                'eap_alloc_doc_status_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_ap_allocations');
        Schema::dropIfExists('enterprise_ap_documents');
    }
};
