<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('collection_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('currency', 3);
            $table->string('status', 24)->default('active');
            $table->decimal('custody_limit', 14, 3)->nullable();
            $table->timestamps();
            $table->unique(['actor_type', 'actor_id', 'store_id', 'currency'], 'collection_account_actor_scope_currency_unique');
        });

        Schema::create('collection_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_account_id')->constrained('collection_accounts');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('type', 24)->default('collection');
            $table->string('status', 24)->default('posted');
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->string('source', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('collection_transactions')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['collection_account_id', 'created_at']);
        });

        Schema::create('collection_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_transaction_id')->constrained('collection_transactions')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['collection_transaction_id', 'invoice_id'], 'collection_allocation_unique');
        });

        Schema::create('custody_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_account_id')->constrained('collection_accounts');
            $table->string('entry_type', 32);
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['collection_account_id', 'created_at'], 'custody_ledger_account_time');
            $table->unique(['reference_type', 'reference_id', 'entry_type'], 'custody_ledger_reference_unique');
        });

        Schema::create('remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_account_id')->constrained('collection_accounts');
            $table->string('idempotency_key', 128)->unique();
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->string('method', 64);
            $table->string('reference')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['collection_account_id', 'status']);
        });

        Schema::create('remittance_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remittance_id')->constrained('remittances')->cascadeOnDelete();
            $table->foreignId('collection_transaction_id')->constrained('collection_transactions');
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['remittance_id', 'collection_transaction_id'], 'remittance_allocation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_allocations');
        Schema::dropIfExists('remittances');
        Schema::dropIfExists('custody_ledger_entries');
        Schema::dropIfExists('collection_allocations');
        Schema::dropIfExists('collection_transactions');
        Schema::dropIfExists('collection_accounts');
    }
};
