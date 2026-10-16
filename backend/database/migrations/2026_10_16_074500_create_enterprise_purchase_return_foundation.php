<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('return_number', 32);
            $table->unsignedBigInteger('goods_receipt_id');
            $table->foreign('goods_receipt_id', 'epr_goods_receipt_fk')
                ->references('id')
                ->on('enterprise_goods_receipts')
                ->restrictOnDelete();
            $table->string('source_receipt_number', 32);
            $table->string('source_order_number', 32);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('supplier_code', 64);
            $table->string('supplier_name_en', 190);
            $table->string('supplier_name_ar', 190)->nullable();
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'return_number'],
                'epr_company_number_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'id'],
                'epr_scope_status_idx',
            );
            $table->index(
                ['goods_receipt_id', 'status', 'id'],
                'epr_grn_status_idx',
            );
        });

        Schema::create('enterprise_purchase_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->foreign('purchase_return_id', 'eprl_return_fk')
                ->references('id')
                ->on('enterprise_purchase_returns')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('goods_receipt_line_id');
            $table->foreign('goods_receipt_line_id', 'eprl_grn_line_fk')
                ->references('id')
                ->on('enterprise_goods_receipt_lines')
                ->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->foreign('purchase_order_line_id', 'eprl_po_line_fk')
                ->references('id')
                ->on('enterprise_purchase_order_lines')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('received_quantity', 14, 3);
            $table->decimal('returned_quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('unit_price', 18, 3)->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->foreign('warehouse_id', 'eprl_warehouse_fk')
                ->references('id')
                ->on('warehouses')
                ->restrictOnDelete();
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['purchase_return_id', 'goods_receipt_line_id'],
                'eprl_return_grn_line_uq',
            );
            $table->unique(
                ['purchase_return_id', 'line_no'],
                'eprl_return_line_no_uq',
            );
            $table->index(
                ['goods_receipt_line_id', 'purchase_return_id'],
                'eprl_grn_line_return_idx',
            );
            $table->index(
                ['warehouse_id', 'id'],
                'eprl_warehouse_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_purchase_return_lines');
        Schema::dropIfExists('enterprise_purchase_returns');
    }
};
