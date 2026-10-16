<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_inventory_valuation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('cost_method', 24)->default('standard');
            $table->decimal('unit_cost', 18, 3);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id'],
                'eivp_warehouse_product_uq',
            );
            $table->index(
                ['store_id', 'is_active', 'warehouse_id', 'product_id'],
                'eivp_store_active_wh_product_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_inventory_valuation_policies');
    }
};
