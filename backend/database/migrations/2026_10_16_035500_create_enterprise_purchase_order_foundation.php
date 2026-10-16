<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('order_number', 32);
            $table->foreignId('purchase_request_id')
                ->unique()
                ->constrained('enterprise_purchase_requests')
                ->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'order_number'],
                'enterprise_purchase_orders_company_number_unique',
            );
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'id'],
                'enterprise_purchase_orders_scope_status_index',
            );
            $table->index(
                ['supplier_id', 'status', 'id'],
                'enterprise_purchase_orders_supplier_status_index',
            );
        });

        Schema::create('enterprise_purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('enterprise_purchase_orders')
                ->cascadeOnDelete();
            $table->foreignId('source_purchase_request_line_id')
                ->constrained('enterprise_purchase_request_lines')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('unit_cost', 18, 3)->nullable();
            $table->timestamps();

            $table->unique(
                ['purchase_order_id', 'line_no'],
                'enterprise_purchase_order_lines_number_unique',
            );
            $table->unique(
                ['purchase_order_id', 'source_purchase_request_line_id'],
                'enterprise_purchase_order_lines_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_purchase_order_lines');
        Schema::dropIfExists('enterprise_purchase_orders');
    }
};
