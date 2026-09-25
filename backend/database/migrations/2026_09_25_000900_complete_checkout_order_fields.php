<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('checkout_idempotency_key', 100)->nullable();
            $table->string('checkout_request_hash', 64)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->text('customer_note')->nullable();
            $table->unique(
                ['customer_id', 'checkout_idempotency_key'],
                'orders_customer_checkout_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_customer_checkout_idempotency_unique');
            $table->dropColumn([
                'checkout_idempotency_key',
                'checkout_request_hash',
                'payment_method',
                'customer_note',
            ]);
        });
    }
};
