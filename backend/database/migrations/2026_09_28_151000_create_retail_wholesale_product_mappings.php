<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_wholesale_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retail_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('wholesale_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('retail_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_conversion_factor', 14, 6)->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['retail_store_id', 'wholesale_product_id'], 'retail_wholesale_product_mapping_unique');
            $table->index(['retail_store_id', 'retail_product_id'], 'retail_wholesale_product_mapping_retail_idx');
        });

        Schema::table('retail_replenishment_items', function (Blueprint $table): void {
            $table->decimal('source_quantity', 14, 3)->nullable()->after('retail_product_id');
            $table->decimal('quantity_conversion_factor', 14, 6)->default(1)->after('source_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('retail_replenishment_items', function (Blueprint $table): void {
            $table->dropColumn(['source_quantity', 'quantity_conversion_factor']);
        });

        Schema::dropIfExists('retail_wholesale_product_mappings');
    }
};
