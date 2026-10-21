<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('product_supplier_brand_sources')) {
            Schema::create('product_supplier_brand_sources', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 64)->default('foodex_sqlserver');
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->nullOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->unsignedBigInteger('source_supplier_id');
                $table->unsignedInteger('purchase_document_count')->default(0);
                $table->unsignedInteger('return_document_count')->default(0);
                $table->decimal('net_purchase_quantity', 20, 3)->default(0);
                $table->decimal('net_purchase_value', 20, 3)->default(0);
                $table->date('first_document_date')->nullable();
                $table->date('last_document_date')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->string('selection_rule', 80)->nullable();
                $table->timestamps();

                $table->unique(
                    ['source_system', 'product_id', 'source_supplier_id'],
                    'product_supplier_brand_source_uq',
                );
                $table->index(
                    ['product_id', 'is_primary', 'id'],
                    'product_supplier_brand_primary_idx',
                );
                $table->index(
                    ['source_supplier_id', 'product_id'],
                    'product_supplier_brand_supplier_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_brand_sources');
    }
};
