<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('van_inventory_history_documents')) {
            Schema::create('van_inventory_history_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 64)->default('foodex_sqlserver');
                $table->string('source_table', 128)->default('wh_CarTransHeader');
                $table->unsignedBigInteger('source_row_ordinal');
                $table->text('source_key')->nullable();
                $table->unsignedBigInteger('legacy_header_id')->nullable()->index();
                $table->foreignId('van_id')->nullable()->constrained('vans')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->integer('legacy_transaction_type')->nullable()->index();
                $table->string('document_number', 160)->nullable()->index();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->string('legacy_status', 80)->nullable();
                $table->char('source_hash', 64);
                $table->longText('source_payload');
                $table->timestamps();

                $table->unique(
                    ['source_system', 'source_table', 'source_row_ordinal'],
                    'van_inv_hist_doc_source_uq'
                );
            });
        }

        if (! Schema::hasTable('van_inventory_history_lines')) {
            Schema::create('van_inventory_history_lines', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 64)->default('foodex_sqlserver');
                $table->string('source_table', 128)->default('wh_CarTransDetails');
                $table->unsignedBigInteger('source_row_ordinal');
                $table->text('source_key')->nullable();
                $table->foreignId('document_id')->nullable()
                    ->constrained('van_inventory_history_documents')->cascadeOnDelete();
                $table->unsignedBigInteger('legacy_detail_id')->nullable()->index();
                $table->unsignedBigInteger('legacy_header_id')->nullable()->index();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->decimal('quantity', 18, 3)->nullable();
                $table->decimal('unit_price', 18, 6)->nullable();
                $table->decimal('line_total', 18, 6)->nullable();
                $table->char('source_hash', 64);
                $table->longText('source_payload');
                $table->timestamps();

                $table->unique(
                    ['source_system', 'source_table', 'source_row_ordinal'],
                    'van_inv_hist_line_source_uq'
                );
                $table->index(['document_id', 'product_id'], 'van_inv_hist_line_doc_product_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('van_inventory_history_lines');
        Schema::dropIfExists('van_inventory_history_documents');
    }
};
