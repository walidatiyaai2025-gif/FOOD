<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enterprise_suppliers', function (Blueprint $table): void {
            $table->string('qualification_status', 24)->default('unqualified');
            $table->unsignedSmallInteger('default_lead_time_days')->nullable();
            $table->string('performance_tier', 24)->nullable();
            $table->text('blocked_reason')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(
                ['organization_id', 'company_id', 'qualification_status', 'id'],
                'es_qualification_idx',
            );
        });

        Schema::create('enterprise_supplier_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('role', 96)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'is_active', 'id'], 'esc_supplier_active_idx');
        });

        Schema::create('enterprise_procurement_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('rfq_number', 32);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->nullable()
                ->constrained('enterprise_purchase_requests')->nullOnDelete();
            $table->string('title', 190);
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('draft');
            $table->timestamp('closes_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'rfq_number'],
                'epr_company_number_uq',
            );
            $table->index(['store_id', 'status', 'closes_at', 'id'], 'epr_store_status_idx');
        });

        Schema::create('enterprise_procurement_rfq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_request_line_id')->nullable();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('estimated_unit_cost', 18, 3)->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'line_no'], 'eprl_rfq_line_uq');
            $table->index(['rfq_id', 'id'], 'eprl_rfq_idx');
        });

        Schema::create('enterprise_procurement_rfq_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('invited');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_id'], 'eprs_rfq_supplier_uq');
            $table->index(['supplier_id', 'status', 'rfq_id'], 'eprs_supplier_status_idx');
        });

        Schema::create('enterprise_procurement_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('quote_number', 32);
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('supplier_reference', 96)->nullable();
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('draft');
            $table->decimal('total_amount', 18, 3)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_id'], 'epq_rfq_supplier_uq');
            $table->unique(['rfq_id', 'quote_number'], 'epq_rfq_number_uq');
            $table->index(['supplier_id', 'status', 'id'], 'epq_supplier_status_idx');
        });

        Schema::create('enterprise_procurement_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('enterprise_procurement_quotes')->cascadeOnDelete();
            $table->foreignId('rfq_line_id')->constrained('enterprise_procurement_rfq_lines')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 18, 3);
            $table->decimal('line_total', 18, 3);
            $table->timestamps();

            $table->unique(['quote_id', 'rfq_line_id'], 'epql_quote_rfq_line_uq');
            $table->unique(['quote_id', 'line_no'], 'epql_quote_line_uq');
        });

        Schema::table('enterprise_procurement_rfqs', function (Blueprint $table): void {
            $table->foreignId('awarded_quote_id')->nullable()
                ->constrained('enterprise_procurement_quotes')->nullOnDelete();
        });

        Schema::create('enterprise_procurement_contracts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('contract_number', 32);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('title', 190);
            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->decimal('ceiling_amount', 18, 3)->nullable();
            $table->decimal('ceiling_quantity', 18, 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'contract_number'],
                'epc_company_number_uq',
            );
            $table->index(['store_id', 'status', 'valid_to', 'id'], 'epc_store_status_idx');
            $table->index(['supplier_id', 'status', 'valid_to', 'id'], 'epc_supplier_status_idx');
        });

        Schema::create('enterprise_procurement_contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('enterprise_procurement_contracts')->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('reference', 190)->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'expires_at', 'id'], 'epcd_contract_expiry_idx');
        });

        Schema::create('enterprise_procurement_contract_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('enterprise_procurement_contracts')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('enterprise_purchase_orders')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'purchase_order_id'], 'epco_contract_order_uq');
            $table->unique('purchase_order_id', 'epco_order_uq');
        });

        Schema::table('enterprise_purchase_orders', function (Blueprint $table): void {
            $table->foreignId('sourcing_rfq_id')->nullable()
                ->constrained('enterprise_procurement_rfqs')->nullOnDelete();
            $table->foreignId('sourcing_quote_id')->nullable()
                ->constrained('enterprise_procurement_quotes')->nullOnDelete();
        });

        Schema::create('enterprise_procurement_match_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->unique()
                ->constrained('enterprise_purchase_invoices')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('source_fingerprint', 64);
            $table->decimal('tolerance_percent', 8, 3)->default(0);
            $table->decimal('quantity_variance', 18, 3)->default(0);
            $table->decimal('receipt_variance', 18, 3)->default(0);
            $table->decimal('price_variance', 18, 3)->default(0);
            $table->decimal('value_variance', 18, 3)->default(0);
            $table->json('line_results');
            $table->text('exception_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at', 'id'], 'epmr_status_idx');
        });

        Schema::create('enterprise_supplier_portal_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('capabilities');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'expires_at', 'revoked_at'], 'espt_supplier_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_supplier_portal_tokens');
        Schema::dropIfExists('enterprise_procurement_match_reviews');

        Schema::table('enterprise_purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sourcing_quote_id');
            $table->dropConstrainedForeignId('sourcing_rfq_id');
        });

        Schema::dropIfExists('enterprise_procurement_contract_orders');
        Schema::dropIfExists('enterprise_procurement_contract_documents');
        Schema::dropIfExists('enterprise_procurement_contracts');

        Schema::table('enterprise_procurement_rfqs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('awarded_quote_id');
        });

        Schema::dropIfExists('enterprise_procurement_quote_lines');
        Schema::dropIfExists('enterprise_procurement_quotes');
        Schema::dropIfExists('enterprise_procurement_rfq_suppliers');
        Schema::dropIfExists('enterprise_procurement_rfq_lines');
        Schema::dropIfExists('enterprise_procurement_rfqs');
        Schema::dropIfExists('enterprise_supplier_contacts');

        Schema::table('enterprise_suppliers', function (Blueprint $table): void {
            $table->dropForeign(['blocked_by']);
            $table->dropIndex('es_qualification_idx');
            $table->dropColumn([
                'qualification_status',
                'default_lead_time_days',
                'performance_tier',
                'blocked_reason',
                'blocked_at',
                'blocked_by',
            ]);
        });
    }
};
