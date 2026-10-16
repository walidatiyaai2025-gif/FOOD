<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('enterprise_finance_cost_centers')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'efcc_legal_code_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'code'],
                'efcc_scope_status_code_idx',
            );
            $table->index(['parent_id', 'status', 'id'], 'efcc_parent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_cost_centers');
    }
};
