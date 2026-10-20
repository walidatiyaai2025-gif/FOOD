<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_procurement_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64);
            $table->unsignedBigInteger('source_header_id');
            $table->unsignedTinyInteger('source_trans_type');
            $table->string('document_kind', 32);
            $table->date('document_date');
            $table->unsignedBigInteger('source_supplier_id');
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->nullOnDelete();
            $table->unsignedBigInteger('source_location_id');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->char('currency_code', 3)->default('EGP');
            $table->decimal('gross_total', 18, 3)->default(0);
            $table->decimal('net_total', 18, 3)->default(0);
            $table->decimal('paid_amount', 18, 3)->default(0);
            $table->decimal('legacy_balance', 18, 3)->default(0);
            $table->unsignedSmallInteger('legacy_price_type')->nullable();
            $table->unsignedSmallInteger('legacy_pay_type')->nullable();
            $table->string('document_number', 128)->nullable();
            $table->string('source_code', 128)->nullable();
            $table->string('reference_number', 128)->nullable();
            $table->text('notes')->nullable();
            $table->json('source_payload');
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_header_id'],
                'legacy_procurement_documents_source_uq',
            );
            $table->index(
                ['supplier_id', 'document_date', 'document_kind', 'id'],
                'legacy_procurement_documents_supplier_idx',
            );
            $table->index(
                ['store_id', 'document_date', 'document_kind', 'id'],
                'legacy_procurement_documents_store_idx',
            );
        });

        Schema::create('legacy_procurement_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('legacy_procurement_documents')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('source_detail_id');
            $table->unsignedBigInteger('source_item_id');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('package')->nullable();
            $table->decimal('raw_quantity', 18, 3)->default(0);
            $table->decimal('base_quantity', 18, 3)->default(0);
            $table->decimal('unit_price', 18, 3)->default(0);
            $table->decimal('base_unit_price', 18, 3)->default(0);
            $table->decimal('line_value', 18, 3)->default(0);
            $table->decimal('cost_price', 18, 3)->default(0);
            $table->decimal('discount_total', 18, 3)->default(0);
            $table->decimal('tax_total', 18, 3)->default(0);
            $table->json('source_payload');
            $table->timestamps();

            $table->unique('source_detail_id', 'legacy_procurement_lines_source_detail_uq');
            $table->index(['document_id', 'id'], 'legacy_procurement_lines_document_idx');
            $table->index(['product_id', 'id'], 'legacy_procurement_lines_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_procurement_document_lines');
        Schema::dropIfExists('legacy_procurement_documents');
    }
};
