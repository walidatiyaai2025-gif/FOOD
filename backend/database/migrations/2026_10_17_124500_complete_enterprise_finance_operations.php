<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enterprise_finance_accounts', function (Blueprint $table): void {
            $table->string('cash_flow_classification', 24)->nullable();
            $table->index(
                ['legal_entity_id', 'cash_flow_classification', 'status', 'id'],
                'efa_cashflow_idx',
            );
        });

        Schema::create('enterprise_profit_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('enterprise_profit_centers')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'epc_legal_code_uq');
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'status', 'code'],
                'epc_scope_status_idx',
            );
        });

        Schema::table('enterprise_finance_journal_lines', function (Blueprint $table): void {
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('enterprise_cost_centers')->restrictOnDelete();
            $table->foreignId('profit_center_id')->nullable()
                ->constrained('enterprise_profit_centers')->restrictOnDelete();
            $table->index(['cost_center_id', 'journal_id', 'id'], 'efjl_cost_center_idx');
            $table->index(['profit_center_id', 'journal_id', 'id'], 'efjl_profit_center_idx');
        });

        Schema::create('enterprise_fixed_asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->string('depreciation_method', 32)->default('straight_line');
            $table->unsignedSmallInteger('default_useful_life_months');
            $table->decimal('default_declining_rate_percent', 8, 3)->nullable();
            $table->foreignId('asset_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id');
            $table->foreign('accumulated_depreciation_account_id', 'efac_accum_dep_account_fk')
                ->references('id')->on('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id');
            $table->foreign('depreciation_expense_account_id', 'efac_dep_expense_account_fk')
                ->references('id')->on('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('disposal_gain_account_id')->nullable();
            $table->foreign('disposal_gain_account_id', 'efac_disposal_gain_account_fk')
                ->references('id')->on('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('disposal_loss_account_id')->nullable();
            $table->foreign('disposal_loss_account_id', 'efac_disposal_loss_account_fk')
                ->references('id')->on('enterprise_finance_accounts')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'efac_legal_code_uq');
            $table->index(['legal_entity_id', 'status', 'id'], 'efac_status_idx');
        });

        Schema::create('enterprise_fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('enterprise_fixed_asset_categories')->restrictOnDelete();
            $table->string('asset_code', 64);
            $table->string('name', 190);
            $table->date('acquisition_date');
            $table->date('capitalization_date');
            $table->decimal('acquisition_amount', 18, 3);
            $table->decimal('salvage_value', 18, 3)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 32);
            $table->decimal('declining_rate_percent', 8, 3)->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('enterprise_cost_centers')->restrictOnDelete();
            $table->foreignId('profit_center_id')->nullable()->constrained('enterprise_profit_centers')->restrictOnDelete();
            $table->foreignId('acquisition_offset_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('acquisition_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_proceeds', 18, 3)->nullable();
            $table->foreignId('disposal_journal_id')->nullable()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'asset_code'], 'efa_asset_code_uq');
            $table->index(['legal_entity_id', 'status', 'capitalization_date', 'id'], 'efa_asset_status_idx');
            $table->index(['category_id', 'status', 'id'], 'efa_category_status_idx');
        });

        Schema::create('enterprise_fixed_asset_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('enterprise_fixed_assets')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id');
            $table->foreign('fiscal_period_id', 'efadr_fiscal_period_fk')
                ->references('id')->on('enterprise_fiscal_periods')->restrictOnDelete();
            $table->decimal('amount', 18, 3);
            $table->decimal('book_value_before', 18, 3);
            $table->decimal('book_value_after', 18, 3);
            $table->foreignId('journal_id')->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'fiscal_period_id'], 'efadr_asset_period_uq');
            $table->index(['fiscal_period_id', 'status', 'id'], 'efadr_period_status_idx');
        });

        Schema::create('enterprise_finance_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('enterprise_fiscal_years')->restrictOnDelete();
            $table->string('name', 190);
            $table->unsignedSmallInteger('version')->default(1);
            $table->char('currency_code', 3);
            $table->string('status', 16)->default('draft');
            $table->text('revision_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'fiscal_year_id', 'version'], 'efb_year_version_uq');
            $table->index(['legal_entity_id', 'status', 'fiscal_year_id', 'id'], 'efb_status_year_idx');
        });

        Schema::create('enterprise_finance_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('enterprise_finance_budgets')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('enterprise_fiscal_periods')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('enterprise_cost_centers')->restrictOnDelete();
            $table->foreignId('profit_center_id')->nullable()->constrained('enterprise_profit_centers')->restrictOnDelete();
            $table->decimal('amount', 18, 3);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['budget_id', 'fiscal_period_id', 'account_id', 'cost_center_id', 'profit_center_id'],
                'efbl_dimension_uq',
            );
            $table->index(['fiscal_period_id', 'account_id', 'id'], 'efbl_period_account_idx');
        });

        Schema::create('enterprise_finance_tax_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 190);
            $table->decimal('rate_percent', 8, 3);
            $table->string('direction', 16);
            $table->decimal('recoverable_percent', 8, 3)->default(100);
            $table->foreignId('input_account_id')->nullable()->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('output_account_id')->nullable()->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code', 'effective_from'], 'eftc_code_effective_uq');
            $table->index(['legal_entity_id', 'status', 'effective_from', 'effective_to'], 'eftc_effective_idx');
        });

        Schema::create('enterprise_finance_tax_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('enterprise_finance_tax_codes')->restrictOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained('enterprise_finance_journal_lines')->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('taxable_amount', 18, 3);
            $table->decimal('tax_amount', 18, 3);
            $table->string('source_reference', 128)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['legal_entity_id', 'direction', 'created_at', 'id'], 'efte_scope_direction_idx');
            $table->index(['tax_code_id', 'created_at', 'id'], 'efte_tax_code_idx');
        });

        Schema::create('enterprise_finance_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 190);
            $table->foreignId('account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->foreignId('source_cost_center_id')->nullable();
            $table->foreign('source_cost_center_id', 'efar_source_cost_center_fk')
                ->references('id')->on('enterprise_cost_centers')->restrictOnDelete();
            $table->foreignId('source_profit_center_id')->nullable();
            $table->foreign('source_profit_center_id', 'efar_source_profit_center_fk')
                ->references('id')->on('enterprise_profit_centers')->restrictOnDelete();
            $table->foreignId('target_cost_center_id')->nullable();
            $table->foreign('target_cost_center_id', 'efar_target_cost_center_fk')
                ->references('id')->on('enterprise_cost_centers')->restrictOnDelete();
            $table->foreignId('target_profit_center_id')->nullable();
            $table->foreign('target_profit_center_id', 'efar_target_profit_center_fk')
                ->references('id')->on('enterprise_profit_centers')->restrictOnDelete();
            $table->decimal('allocation_percent', 8, 3);
            $table->string('basis', 32)->default('manual_amount');
            $table->text('basis_description');
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code'], 'efar_legal_code_uq');
            $table->index(['legal_entity_id', 'status', 'id'], 'efar_status_idx');
        });

        Schema::create('enterprise_finance_close_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_period_id')->constrained('enterprise_fiscal_periods')->cascadeOnDelete();
            $table->string('check_code', 64);
            $table->boolean('is_blocking')->default(true);
            $table->string('status', 16);
            $table->unsignedInteger('exception_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('evaluated_at');
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_period_id', 'check_code'], 'efcc_period_check_uq');
            $table->index(['fiscal_period_id', 'is_blocking', 'status'], 'efcc_blocking_idx');
        });

        Schema::create('enterprise_finance_consolidation_eliminations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('enterprise_companies')->cascadeOnDelete();
            $table->string('fiscal_year_code', 64);
            $table->string('period_code', 64);
            $table->string('account_type', 24);
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
            $table->string('description', 255);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['company_id', 'fiscal_year_code', 'period_code', 'is_active', 'id'],
                'efce_company_period_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_consolidation_eliminations');
        Schema::dropIfExists('enterprise_finance_close_checks');
        Schema::dropIfExists('enterprise_finance_allocation_rules');
        Schema::dropIfExists('enterprise_finance_tax_entries');
        Schema::dropIfExists('enterprise_finance_tax_codes');
        Schema::dropIfExists('enterprise_finance_budget_lines');
        Schema::dropIfExists('enterprise_finance_budgets');
        Schema::dropIfExists('enterprise_fixed_asset_depreciation_runs');
        Schema::dropIfExists('enterprise_fixed_assets');
        Schema::dropIfExists('enterprise_fixed_asset_categories');

        Schema::table('enterprise_finance_journal_lines', function (Blueprint $table): void {
            $table->dropIndex('efjl_profit_center_idx');
            $table->dropIndex('efjl_cost_center_idx');
            $table->dropConstrainedForeignId('profit_center_id');
            $table->dropConstrainedForeignId('cost_center_id');
        });

        Schema::dropIfExists('enterprise_profit_centers');

        Schema::table('enterprise_finance_accounts', function (Blueprint $table): void {
            $table->dropIndex('efa_cashflow_idx');
            $table->dropColumn('cash_flow_classification');
        });
    }
};
