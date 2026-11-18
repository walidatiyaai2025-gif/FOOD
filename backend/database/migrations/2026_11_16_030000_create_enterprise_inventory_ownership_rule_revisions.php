<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_inventory_ownership_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('rule_uuid');
            $table->unsignedInteger('revision');
            $table->foreignId('company_id')->constrained('enterprise_companies', 'id', 'eior_company_fk')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores', 'id', 'eior_store_fk')->restrictOnDelete();
            $table->string('channel', 8);
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers', 'id', 'eior_supplier_fk')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses', 'id', 'eior_warehouse_fk')->restrictOnDelete();
            $table->foreignId('supplier_document_id')->nullable()->constrained('enterprise_supplier_documents', 'id', 'eior_document_fk')->restrictOnDelete();
            $table->string('initial_owner_kind', 16);
            $table->string('title_transfer_trigger', 32);
            $table->json('required_evidence_classes');
            $table->string('checker_permission', 96);
            $table->timestamp('effective_at');
            $table->string('status', 24)->default('DRAFT');
            $table->uuid('request_uuid');
            $table->char('payload_sha256', 64);
            $table->foreignId('proposed_by')->constrained('users', 'id', 'eior_proposer_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'eior_reviewer_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['rule_uuid', 'revision'], 'eior_rule_revision_uq');
            $table->unique('request_uuid', 'eior_request_uuid_uq');
            $table->index(['store_id', 'channel', 'status', 'effective_at'], 'eior_active_scope_idx');
        });
    }

    public function down(): void
    {
        // Policy and independent review history is audit evidence. An empty
        // migration can roll back; populated history must be retained.
        if (Schema::hasTable('enterprise_inventory_ownership_rule_revisions')
            && DB::table('enterprise_inventory_ownership_rule_revisions')->exists()) {
            throw new RuntimeException('Inventory ownership policy history must be explicitly preserved before rollback.');
        }

        Schema::dropIfExists('enterprise_inventory_ownership_rule_revisions');
    }
};
