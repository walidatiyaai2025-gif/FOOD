<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_treasury_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('receipt_number', 48);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id');
            $table->decimal('amount', 18, 3);
            $table->string('currency_code', 3);
            $table->date('value_date');
            $table->string('reference', 180);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'receipt_number'], 'etr_store_number_uq');
            $table->unique(['store_id', 'idempotency_key'], 'etr_store_idempotency_uq');
            $table->index(
                ['organization_id', 'company_id', 'store_id', 'status', 'value_date', 'id'],
                'etr_scope_status_date_idx',
            );
            $table->index(
                ['store_id', 'target_type', 'target_id', 'status', 'value_date', 'id'],
                'etr_target_status_date_idx',
            );
            $table->index(['store_id', 'reference', 'id'], 'etr_store_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_treasury_receipts');
    }
};
