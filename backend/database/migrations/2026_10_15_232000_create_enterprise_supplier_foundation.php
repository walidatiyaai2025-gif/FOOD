<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('scope_type', 16)->default('store');
            $table->string('code', 64);
            $table->string('name_en', 190);
            $table->string('name_ar', 190)->nullable();
            $table->string('status', 24)->default('active');
            $table->string('contact_name', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('tax_registration_number', 96)->nullable();
            $table->string('commercial_registration_number', 96)->nullable();
            $table->string('business_type', 96)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'company_id', 'code'],
                'enterprise_suppliers_company_code_unique',
            );
            $table->index(
                ['organization_id', 'company_id', 'status', 'id'],
                'enterprise_suppliers_scope_status_index',
            );
            $table->index(
                ['organization_id', 'company_id', 'name_en', 'id'],
                'enterprise_suppliers_scope_name_index',
            );
            $table->index(
                ['store_id', 'status', 'id'],
                'enterprise_suppliers_store_status_index',
            );
        });

        Schema::create('enterprise_supplier_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('enterprise_suppliers')->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('title', 190);
            $table->string('reference', 190)->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'category', 'id'], 'enterprise_supplier_documents_lookup_index');
            $table->index(['supplier_id', 'expires_at'], 'enterprise_supplier_documents_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_supplier_documents');
        Schema::dropIfExists('enterprise_suppliers');
    }
};
