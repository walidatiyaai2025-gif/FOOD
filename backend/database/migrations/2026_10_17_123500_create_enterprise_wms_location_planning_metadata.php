<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_warehouse_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code'], 'ewz_warehouse_code_uq');
            $table->index(['store_id', 'warehouse_id', 'is_active'], 'ewz_scope_active_idx');
        });

        Schema::create('enterprise_warehouse_bins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('enterprise_warehouse_zones')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code'], 'ewb_warehouse_code_uq');
            $table->index(['store_id', 'warehouse_id', 'is_active'], 'ewb_scope_active_idx');
            $table->index(['zone_id', 'is_active'], 'ewb_zone_active_idx');
        });

        Schema::create('enterprise_inventory_bin_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('inventory_id')->unique()->constrained('inventories')->cascadeOnDelete();
            $table->foreignId('bin_id')->constrained('enterprise_warehouse_bins')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->index(['warehouse_id', 'bin_id', 'inventory_id'], 'eiba_wh_bin_inventory_idx');
        });

        Schema::create('enterprise_wms_bin_provenance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('bin_id')->constrained('enterprise_warehouse_bins')->restrictOnDelete();
            $table->string('subject_type', 96);
            $table->unsignedBigInteger('subject_id');
            $table->string('role', 48);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['subject_type', 'subject_id', 'role'], 'ewbp_subject_role_uq');
            $table->index(['warehouse_id', 'bin_id', 'created_at'], 'ewbp_wh_bin_created_idx');
        });

        Schema::create('enterprise_wms_demand_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->date('as_of');
            $table->char('input_fingerprint', 64);
            $table->decimal('recommended_quantity', 14, 3);
            $table->string('status', 24)->default('approved');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id', 'as_of', 'input_fingerprint'],
                'ewda_scope_input_uq',
            );
            $table->index(['store_id', 'warehouse_id', 'status', 'as_of'], 'ewda_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wms_demand_approvals');
        Schema::dropIfExists('enterprise_wms_bin_provenance');
        Schema::dropIfExists('enterprise_inventory_bin_assignments');
        Schema::dropIfExists('enterprise_warehouse_bins');
        Schema::dropIfExists('enterprise_warehouse_zones');
    }
};
