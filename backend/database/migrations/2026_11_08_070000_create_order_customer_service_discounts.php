<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_customer_service_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('applied_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('revision');
            $table->decimal('percentage', 6, 3);
            $table->text('reason');
            $table->decimal('prior_customer_service_discount', 14, 3)->default(0);
            $table->decimal('customer_service_discount', 14, 3);
            $table->decimal('discount_total_before', 14, 3);
            $table->decimal('discount_total_after', 14, 3);
            $table->decimal('grand_total_before', 14, 3);
            $table->decimal('grand_total_after', 14, 3);
            $table->string('order_status', 32);
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key'], 'order_cs_discount_idempotency');
            $table->unique(['order_id', 'revision'], 'order_cs_discount_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_customer_service_discounts');
    }
};
