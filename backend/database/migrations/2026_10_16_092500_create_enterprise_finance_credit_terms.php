<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_credit_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('subject_type', 24);
            $table->string('subject_reference', 128);
            $table->decimal('credit_limit', 18, 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['legal_entity_id', 'subject_type', 'subject_reference', 'valid_from'],
                'efct_subject_start_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'subject_type', 'status', 'valid_from', 'valid_until'],
                'efct_scope_subject_effective_idx',
            );
            $table->index(
                ['legal_entity_id', 'subject_type', 'subject_reference', 'status', 'valid_from'],
                'efct_subject_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_credit_terms');
    }
};
