<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->string('type', 24);
            $table->string('normal_balance', 16);
            $table->boolean('is_control')->default(false);
            $table->boolean('allow_posting')->default(true);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'efa_legal_code_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'code'],
                'efa_scope_status_code_idx',
            );
            $table->index(['legal_entity_id', 'type', 'status', 'id'], 'efa_type_status_idx');
            $table->index(['parent_id', 'status', 'id'], 'efa_parent_status_idx');
        });

        Schema::create('enterprise_fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'efy_legal_code_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'starts_on'],
                'efy_scope_status_date_idx',
            );
            $table->index(['legal_entity_id', 'starts_on', 'ends_on'], 'efy_legal_dates_idx');
        });

        Schema::create('enterprise_fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('enterprise_fiscal_years')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('code', 64);
            $table->string('name', 190);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'sequence'], 'efp_year_sequence_unique');
            $table->unique(['fiscal_year_id', 'code'], 'efp_year_code_unique');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'starts_on'],
                'efp_scope_status_date_idx',
            );
            $table->index(['fiscal_year_id', 'status', 'sequence'], 'efp_year_status_seq_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_fiscal_periods');
        Schema::dropIfExists('enterprise_fiscal_years');
        Schema::dropIfExists('enterprise_finance_accounts');
    }
};
