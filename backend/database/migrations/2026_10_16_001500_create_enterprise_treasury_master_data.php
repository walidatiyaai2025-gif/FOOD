<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_cashboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name', 180);
            $table->string('currency_code', 3);
            $table->string('operator_visibility', 20)->default('store');
            $table->string('approval_visibility', 20)->default('store');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['company_id', 'store_id', 'code'], 'enterprise_cashboxes_scope_code_unique');
            $table->index(['store_id', 'is_active'], 'enterprise_cashboxes_store_active_idx');
            $table->index(['company_id', 'currency_code'], 'enterprise_cashboxes_company_currency_idx');
        });

        Schema::create('enterprise_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name', 180);
            $table->string('bank_name', 180);
            $table->string('account_name', 180);
            $table->text('account_reference')->nullable();
            $table->string('account_reference_last4', 4)->nullable();
            $table->string('currency_code', 3);
            $table->string('operator_visibility', 20)->default('store');
            $table->string('approval_visibility', 20)->default('store');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['company_id', 'store_id', 'code'], 'enterprise_bank_accounts_scope_code_unique');
            $table->index(['store_id', 'is_active'], 'enterprise_bank_accounts_store_active_idx');
            $table->index(['company_id', 'currency_code'], 'enterprise_bank_accounts_company_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_bank_accounts');
        Schema::dropIfExists('enterprise_cashboxes');
    }
};
