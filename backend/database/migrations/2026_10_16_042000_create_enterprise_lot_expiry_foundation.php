<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('identifier', 120);
            $table->string('normalized_identifier', 120);
            $table->string('status', 24)->default('active');
            $table->date('manufactured_on')->nullable();
            $table->date('received_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id', 'normalized_identifier'],
                'enterprise_lot_wh_product_identifier_unique',
            );
            $table->index(
                ['store_id', 'expires_on', 'warehouse_id', 'status', 'id'],
                'enterprise_lot_store_expiry_index',
            );
            $table->index(
                ['warehouse_id', 'expires_on', 'status', 'id'],
                'enterprise_lot_warehouse_expiry_index',
            );
            $table->index(
                ['warehouse_id', 'status', 'product_id', 'id'],
                'enterprise_lot_warehouse_status_index',
            );
        });

        Schema::create('enterprise_inventory_lot_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')
                ->constrained('enterprise_inventory_lots')
                ->restrictOnDelete();
            $table->foreignId('stock_movement_id')
                ->constrained('stock_movements')
                ->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                'stock_movement_id',
                'enterprise_lot_movement_stock_unique',
            );
            $table->index(
                ['store_id', 'inventory_lot_id', 'id'],
                'enterprise_lot_movement_store_lot_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_inventory_lot_movements');
        Schema::dropIfExists('enterprise_inventory_lots');
    }
};
