<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('invoice_number', 32);
            $table->string('supplier_invoice_number', 96);
            $table->unsignedBigInteger('goods_receipt_id');
            $table->foreign('goods_receipt_id', 'epi_goods_receipt_fk')
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
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 18, 3)->default(0);
            $table->string('status', 24)->default('draft');
            $table->uuid('outbox_event_id')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_off_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'invoice_number'],
                'epi_company_number_uq',
            );
            $table->unique(
                ['organization_id', 'company_id', 'supplier_id', 'supplier_invoice_number'],
                'epi_supplier_number_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'id'],
                'epi_scope_status_idx',
            );
            $table->index(
                ['goods_receipt_id', 'status', 'id'],
                'epi_grn_status_idx',
            );
            $table->index(
                ['supplier_id', 'invoice_date', 'due_date', 'status', 'id'],
                'epi_supplier_aging_idx',
            );
        });

        Schema::create('enterprise_purchase_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_invoice_id');
            $table->foreign('purchase_invoice_id', 'epil_invoice_fk')
                ->references('id')
                ->on('enterprise_purchase_invoices')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('goods_receipt_line_id');
            $table->foreign('goods_receipt_line_id', 'epil_grn_line_fk')
                ->references('id')
                ->on('enterprise_goods_receipt_lines')
                ->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->foreign('purchase_order_line_id', 'epil_po_line_fk')
                ->references('id')
                ->on('enterprise_purchase_order_lines')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('received_quantity', 14, 3);
            $table->decimal('invoice_quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('unit_price', 18, 3);
            $table->decimal('line_total', 18, 3);
            $table->timestamps();

            $table->unique(
                ['purchase_invoice_id', 'goods_receipt_line_id'],
                'epil_invoice_grn_line_uq',
            );
            $table->unique(
                ['purchase_invoice_id', 'line_no'],
                'epil_invoice_line_no_uq',
            );
            $table->index(
                ['goods_receipt_line_id', 'purchase_invoice_id'],
                'epil_grn_line_invoice_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_purchase_invoice_lines');
        Schema::dropIfExists('enterprise_purchase_invoices');
    }
};
