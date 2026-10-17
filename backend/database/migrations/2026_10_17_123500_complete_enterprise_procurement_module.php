<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enterprise_suppliers', function (Blueprint $table): void {
            $table->string('qualification_status', 24)->default('pending')->after('status');
            $table->text('qualification_note')->nullable()->after('qualification_status');
            $table->text('blocked_reason')->nullable()->after('qualification_note');
            $table->timestamp('blocked_at')->nullable()->after('blocked_reason');
            $table->foreignId('blocked_by')->nullable()->after('blocked_at')->constrained('users')->nullOnDelete();
            $table->timestamp('qualification_updated_at')->nullable()->after('blocked_by');

            $table->index(
                ['organization_id', 'company_id', 'qualification_status', 'id'],
                'ent_suppliers_qualification_idx',
            );
        });

        Schema::create('enterprise_procurement_contracts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('contract_number', 48);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('title', 190);
            $table->char('currency_code', 3);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('value_ceiling', 18, 3)->nullable();
            $table->decimal('quantity_ceiling', 18, 3)->nullable();
            $table->decimal('committed_value', 18, 3)->default(0);
            $table->decimal('committed_quantity', 18, 3)->default(0);
            $table->unsignedBigInteger('renewal_of_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'contract_number'],
                'epc_company_number_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'ends_on', 'id'],
                'epc_scope_status_expiry_idx',
            );
            $table->index(
                ['supplier_id', 'status', 'ends_on', 'id'],
                'epc_supplier_status_expiry_idx',
            );
            $table->foreign('renewal_of_id', 'epc_renewal_fk')
                ->references('id')
                ->on('enterprise_procurement_contracts')
                ->nullOnDelete();
        });

        Schema::create('enterprise_procurement_contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('enterprise_procurement_contracts')->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('reference', 190)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'id'], 'epcd_contract_idx');
        });

        Schema::create('enterprise_procurement_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('rfq_number', 48);
            $table->foreignId('purchase_request_id')
                ->constrained('enterprise_purchase_requests')
                ->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('enterprise_procurement_contracts')->nullOnDelete();
            $table->unsignedBigInteger('awarded_quote_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('title', 190);
            $table->char('currency_code', 3);
            $table->timestamp('response_due_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'rfq_number'],
                'eprfq_company_number_uq',
            );
            $table->unique('purchase_request_id', 'eprfq_request_uq');
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'id'],
                'eprfq_scope_status_idx',
            );
        });

        Schema::create('enterprise_procurement_rfq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->foreignId('purchase_request_line_id');
            $table->foreign('purchase_request_line_id', 'eprfql_request_line_fk')
                ->references('id')
                ->on('enterprise_purchase_request_lines')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('baseline_unit_cost', 18, 3)->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'line_no'], 'eprfql_rfq_line_uq');
            $table->unique('purchase_request_line_id', 'eprfql_request_line_uq');
        });

        Schema::create('enterprise_procurement_rfq_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 24)->default('invited');
            $table->timestamp('expires_at');
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_id'], 'eprfqi_rfq_supplier_uq');
            $table->index(['supplier_id', 'status', 'expires_at', 'id'], 'eprfqi_supplier_status_idx');
        });

        Schema::create('enterprise_procurement_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('quote_number', 48);
            $table->foreignId('rfq_id')->constrained('enterprise_procurement_rfqs')->cascadeOnDelete();
            $table->foreignId('invitation_id')
                ->unique()
                ->constrained('enterprise_procurement_rfq_invitations')
                ->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('submitted');
            $table->char('currency_code', 3);
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('total_amount', 18, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_id'], 'epquote_rfq_supplier_uq');
            $table->index(['rfq_id', 'status', 'total_amount', 'id'], 'epquote_rfq_status_idx');
        });

        Schema::create('enterprise_procurement_quote_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('enterprise_procurement_quotes')->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['quote_id', 'id'], 'epqd_quote_idx');
        });

        Schema::create('enterprise_procurement_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('enterprise_procurement_quotes')->cascadeOnDelete();
            $table->foreignId('rfq_line_id')->constrained('enterprise_procurement_rfq_lines')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 18, 3);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('line_total', 18, 3);
            $table->timestamps();

            $table->unique(['quote_id', 'rfq_line_id'], 'epql_quote_rfq_line_uq');
        });

        Schema::table('enterprise_procurement_rfqs', function (Blueprint $table): void {
            $table->foreign('awarded_quote_id', 'eprfq_awarded_quote_fk')
                ->references('id')
                ->on('enterprise_procurement_quotes')
                ->nullOnDelete();
        });

        Schema::table('enterprise_purchase_orders', function (Blueprint $table): void {
            $table->foreignId('procurement_contract_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('enterprise_procurement_contracts')
                ->nullOnDelete();
            $table->foreignId('procurement_rfq_id')
                ->nullable()
                ->after('procurement_contract_id')
                ->constrained('enterprise_procurement_rfqs')
                ->nullOnDelete();
            $table->foreignId('awarded_quote_id')
                ->nullable()
                ->after('procurement_rfq_id')
                ->constrained('enterprise_procurement_quotes')
                ->nullOnDelete();
        });

        Schema::create('enterprise_procurement_match_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')
                ->unique()
                ->constrained('enterprise_purchase_invoices')
                ->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->decimal('quantity_variance', 18, 3)->default(0);
            $table->decimal('value_variance', 18, 3)->default(0);
            $table->decimal('quantity_tolerance', 18, 3)->default(0);
            $table->decimal('value_tolerance', 18, 3)->default(0);
            $table->text('exception_reason')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id'], 'epmr_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_procurement_match_reviews');

        Schema::table('enterprise_purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('awarded_quote_id');
            $table->dropConstrainedForeignId('procurement_rfq_id');
            $table->dropConstrainedForeignId('procurement_contract_id');
        });

        Schema::table('enterprise_procurement_rfqs', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['awarded_quote_id']);

                return;
            }

            $table->dropForeign('eprfq_awarded_quote_fk');
        });

        Schema::dropIfExists('enterprise_procurement_quote_lines');
        Schema::dropIfExists('enterprise_procurement_quote_documents');
        Schema::dropIfExists('enterprise_procurement_quotes');
        Schema::dropIfExists('enterprise_procurement_rfq_invitations');
        Schema::dropIfExists('enterprise_procurement_rfq_lines');
        Schema::dropIfExists('enterprise_procurement_rfqs');
        Schema::dropIfExists('enterprise_procurement_contract_documents');
        Schema::dropIfExists('enterprise_procurement_contracts');

        Schema::table('enterprise_suppliers', function (Blueprint $table): void {
            $table->dropIndex('ent_suppliers_qualification_idx');
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn([
                'qualification_status',
                'qualification_note',
                'blocked_reason',
                'blocked_at',
                'qualification_updated_at',
            ]);
        });
    }
};
