<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_warehouse_user_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_manage')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'warehouse_id'], 'ewus_user_warehouse_unique');
            $table->index(['warehouse_id', 'is_active'], 'ewus_warehouse_active_idx');
        });

        Schema::create('enterprise_warehouse_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('target_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('idempotency_key', 120);
            $table->char('request_fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'ewt_store_idempotency_unique');
            $table->index(['store_id', 'status', 'created_at'], 'ewt_store_status_created_idx');
            $table->index(['source_warehouse_id', 'status'], 'ewt_source_status_idx');
            $table->index(['target_warehouse_id', 'status'], 'ewt_target_status_idx');
        });

        Schema::create('enterprise_warehouse_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('enterprise_warehouse_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('target_inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();

            $table->unique(['transfer_id', 'product_id'], 'ewtl_transfer_product_unique');
            $table->index(['source_inventory_id', 'transfer_id'], 'ewtl_source_transfer_idx');
            $table->index(['target_inventory_id', 'transfer_id'], 'ewtl_target_transfer_idx');
        });

        Schema::create('enterprise_inventory_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('open');
            $table->string('idempotency_key', 120);
            $table->char('request_fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'eic_store_idempotency_unique');
            $table->index(['store_id', 'status', 'started_at'], 'eic_store_status_started_idx');
            $table->index(['warehouse_id', 'status'], 'eic_warehouse_status_idx');
        });

        Schema::create('enterprise_inventory_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('count_id')->constrained('enterprise_inventory_counts')->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3)->nullable();
            $table->decimal('variance_quantity', 14, 3)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['count_id', 'inventory_id'], 'eicl_count_inventory_unique');
            $table->index(['inventory_id', 'count_id'], 'eicl_inventory_count_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_inventory_count_lines');
        Schema::dropIfExists('enterprise_inventory_counts');
        Schema::dropIfExists('enterprise_warehouse_transfer_lines');
        Schema::dropIfExists('enterprise_warehouse_transfers');
        Schema::dropIfExists('enterprise_warehouse_user_scopes');
    }
};
