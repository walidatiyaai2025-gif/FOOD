<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_customer_id')->constrained('b2b_customers')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('entry_type', 40)->index();
            $table->string('reference', 120)->nullable()->index();
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 14, 3)->default(0);
            $table->decimal('credit', 14, 3)->default(0);
            $table->string('currency', 3);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 80)->default('dashboard');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['b2b_customer_id', 'occurred_at'], 'customer_ledger_customer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_ledger_entries');
    }
};
