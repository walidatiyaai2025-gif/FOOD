<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_payment_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('operation', 24);
            $table->string('idempotency_key', 160);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->decimal('amount', 14, 3)->nullable();
            $table->string('currency', 3);
            $table->string('provider_reference', 190)->nullable()->index();
            $table->string('provider_event_id', 190)->nullable();
            $table->string('provider_status', 100)->nullable();
            $table->decimal('settlement_gross_amount', 14, 3)->nullable();
            $table->decimal('settlement_fee_amount', 14, 3)->nullable();
            $table->decimal('settlement_net_amount', 14, 3)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('mismatch_code', 64)->nullable()->index();
            $table->json('safe_metadata')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key'], 'restaurant_payment_operation_idempotency_unique');
            $table->unique(['provider', 'provider_event_id'], 'restaurant_payment_provider_event_unique');
            $table->index(['payment_id', 'status', 'operation'], 'restaurant_payment_status_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_payment_operations');
    }
};
