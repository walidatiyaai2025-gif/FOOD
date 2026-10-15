<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('request_number', 32);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->nullOnDelete();
            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);
            $table->date('needed_by')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'request_number'],
                'enterprise_purchase_requests_company_number_unique',
            );
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'id'],
                'enterprise_purchase_requests_scope_status_index',
            );
            $table->index(
                ['supplier_id', 'status', 'id'],
                'enterprise_purchase_requests_supplier_status_index',
            );
            $table->index(
                ['store_id', 'needed_by', 'id'],
                'enterprise_purchase_requests_needed_by_index',
            );
        });

        Schema::create('enterprise_purchase_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')
                ->constrained('enterprise_purchase_requests')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('item_code', 96)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32);
            $table->decimal('estimated_unit_cost', 18, 3)->nullable();
            $table->timestamps();

            $table->unique(
                ['purchase_request_id', 'line_no'],
                'enterprise_purchase_request_lines_number_unique',
            );
            $table->index(
                ['purchase_request_id', 'id'],
                'enterprise_purchase_request_lines_request_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_purchase_request_lines');
        Schema::dropIfExists('enterprise_purchase_requests');
    }
};
