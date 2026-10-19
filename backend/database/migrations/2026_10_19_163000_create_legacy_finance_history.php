<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_finance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_type');
            $table->string('type_name_ar', 100)->nullable();
            $table->string('type_name_en', 100)->nullable();
            $table->string('classification', 64)->index();
            $table->date('transaction_date');
            $table->string('receipt_number', 200)->nullable();
            $table->decimal('amount', 18, 3);
            $table->unsignedBigInteger('source_customer_id')->nullable();
            $table->foreignId('b2b_customer_id')->nullable()->constrained('b2b_customers')->nullOnDelete();
            $table->unsignedBigInteger('source_supplier_id')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->nullOnDelete();
            $table->unsignedBigInteger('source_account_id')->nullable();
            $table->unsignedBigInteger('source_invoice_header_id')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('customer_account_ledger_entries')->nullOnDelete();
            $table->unsignedInteger('payment_type')->nullable();
            $table->unsignedInteger('pay_type')->nullable();
            $table->unsignedInteger('cash_box_no')->nullable();
            $table->unsignedInteger('bank_id')->nullable();
            $table->boolean('gl_posted')->default(false);
            $table->unsignedBigInteger('gl_entry_header_id')->nullable();
            $table->string('balance_effect', 16)->default('none');
            $table->json('source_payload');
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_id'],
                'legacy_finance_transactions_source_uq',
            );
            $table->index(
                ['b2b_customer_id', 'transaction_date', 'id'],
                'legacy_finance_customer_date_idx',
            );
            $table->index(
                ['source_invoice_header_id', 'classification'],
                'legacy_finance_invoice_class_idx',
            );
        });

        Schema::create('legacy_bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_type');
            $table->date('transaction_date');
            $table->string('receipt_number', 200)->nullable();
            $table->decimal('amount', 18, 3);
            $table->unsignedInteger('bank_id')->nullable();
            $table->unsignedBigInteger('source_customer_id')->nullable();
            $table->foreignId('b2b_customer_id')->nullable()->constrained('b2b_customers')->nullOnDelete();
            $table->unsignedBigInteger('source_supplier_id')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->nullOnDelete();
            $table->unsignedBigInteger('source_account_id')->nullable();
            $table->boolean('gl_posted')->default(false);
            $table->unsignedBigInteger('gl_entry_header_id')->nullable();
            $table->json('source_payload');
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_id'],
                'legacy_bank_transactions_source_uq',
            );
            $table->index(
                ['source_type', 'transaction_date', 'id'],
                'legacy_bank_type_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_bank_transactions');
        Schema::dropIfExists('legacy_finance_transactions');
    }
};
