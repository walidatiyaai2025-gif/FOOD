<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id(); $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete(); $table->string('name'); $table->string('slug')->unique(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->timestamps();
        });
        Schema::create('units', function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->unsignedTinyInteger('decimal_places')->default(0); $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id(); $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('unit_id')->constrained(); $table->string('sku')->unique(); $table->string('name'); $table->text('description')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->string('path'); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_primary')->default(false); $table->timestamps();
        });
        Schema::create('store_products', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->decimal('price',14,3)->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['store_id','product_id']);
        });
        Schema::create('inventories', function (Blueprint $table): void {
            $table->id(); $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->decimal('quantity',14,3)->default(0); $table->decimal('reserved_quantity',14,3)->default(0); $table->timestamps(); $table->unique(['warehouse_id','product_id']);
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id(); $table->foreignId('inventory_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('type'); $table->decimal('quantity',14,3); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->text('reason')->nullable(); $table->timestamps(); $table->index(['reference_type','reference_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('stock_movements'); Schema::dropIfExists('inventories'); Schema::dropIfExists('store_products'); Schema::dropIfExists('product_images'); Schema::dropIfExists('products'); Schema::dropIfExists('units'); Schema::dropIfExists('brands'); Schema::dropIfExists('categories'); Schema::dropIfExists('warehouses');
    }
};
