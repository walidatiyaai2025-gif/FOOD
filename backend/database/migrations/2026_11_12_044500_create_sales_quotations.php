<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('channel', 3);
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('draft');
            $table->string('currency', 3);
            $table->decimal('subtotal', 14, 3);
            $table->decimal('discount_total', 14, 3);
            $table->decimal('tax_total', 14, 3);
            $table->decimal('grand_total', 14, 3);
            $table->json('pricing_snapshot');
            $table->date('expires_at');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status', 'created_at'], 'sales_quotes_store_status_date');
            $table->index(['channel', 'customer_id'], 'sales_quotes_customer_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotations');
    }
};
