<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_reorder_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('minimum_quantity', 14, 3)->default(0);
            $table->decimal('reorder_quantity', 14, 3);
            $table->decimal('maximum_quantity', 14, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id'],
                'enterprise_reorder_policy_warehouse_product_unique',
            );
            $table->index(
                ['store_id', 'is_active', 'warehouse_id', 'id'],
                'enterprise_reorder_policy_store_active_index',
            );
            $table->index(
                ['warehouse_id', 'is_active', 'product_id'],
                'enterprise_reorder_policy_warehouse_active_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_reorder_policies');
    }
};
