<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_operational_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64);
            $table->unsignedBigInteger('source_header_id');
            $table->unsignedTinyInteger('source_trans_type');
            $table->string('movement_kind', 48);
            $table->smallInteger('stock_sign')->default(0);
            $table->date('document_date');
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedBigInteger('source_target_location_id')->nullable();
            $table->unsignedBigInteger('source_transfer_header_id')->nullable();
            $table->unsignedBigInteger('source_car_header_id')->nullable();
            $table->unsignedBigInteger('source_production_id')->nullable();
            $table->string('pair_family', 32)->nullable();
            $table->string('pair_group_key', 96)->nullable();
            $table->string('pair_role', 32)->nullable();
            $table->decimal('gross_total', 18, 3)->default(0);
            $table->decimal('net_total', 18, 3)->default(0);
            $table->string('document_number', 128)->nullable();
            $table->string('source_code', 128)->nullable();
            $table->string('reference_number', 128)->nullable();
            $table->text('notes')->nullable();
            $table->json('source_payload');
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_header_id'],
                'legacy_operational_documents_source_uq',
            );
            $table->index(
                ['movement_kind', 'document_date', 'id'],
                'legacy_operational_documents_kind_date_idx',
            );
            $table->index(
                ['pair_family', 'pair_group_key', 'source_trans_type', 'id'],
                'legacy_operational_documents_pair_idx',
            );
            $table->index(
                ['warehouse_id', 'document_date', 'id'],
                'legacy_operational_documents_warehouse_idx',
            );
        });

        Schema::create('legacy_operational_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('legacy_operational_documents')
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

            $table->unique('source_detail_id', 'legacy_operational_lines_source_detail_uq');
            $table->index(['document_id', 'id'], 'legacy_operational_lines_document_idx');
            $table->index(['product_id', 'id'], 'legacy_operational_lines_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_operational_document_lines');
        Schema::dropIfExists('legacy_operational_documents');
    }
};
