<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('legacy_fixed_asset_records')) {
            return;
        }

        Schema::create('legacy_fixed_asset_records', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('source_table', 128)->default('gl_Assets');
            $table->string('source_key', 190);
            $table->char('source_hash', 64);

            $table->string('asset_code', 128)->nullable();
            $table->string('name_ar', 255)->nullable();
            $table->string('name_en', 255)->nullable();
            $table->date('acquisition_date')->nullable();

            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('group_id')->nullable();
            $table->bigInteger('sub_group_id')->nullable();
            $table->bigInteger('place_id')->nullable();
            $table->bigInteger('custody_employee_id')->nullable();

            $table->decimal('asset_age_raw', 10, 3)->nullable();
            $table->unsignedSmallInteger('native_useful_life_months_candidate')->nullable();
            $table->decimal('gross_value', 18, 3)->nullable();
            $table->decimal('accumulated_depreciation', 18, 3)->nullable();
            $table->boolean('expired_flag')->nullable();
            $table->string('status_raw', 64)->nullable();

            $table->string('group_code', 128)->nullable();
            $table->string('group_name_ar', 255)->nullable();
            $table->string('group_name_en', 255)->nullable();
            $table->string('sub_group_code', 128)->nullable();
            $table->string('sub_group_name_ar', 255)->nullable();
            $table->string('sub_group_name_en', 255)->nullable();
            $table->decimal('depreciation_rate_percent', 8, 3)->nullable();

            $table->json('native_blockers')->nullable();
            $table->json('source_payload');
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_table', 'source_key'],
                'legacy_fixed_asset_source_uq',
            );
            $table->index(['group_id', 'sub_group_id'], 'legacy_fixed_asset_group_idx');
            $table->index(['branch_id', 'status_raw'], 'legacy_fixed_asset_branch_status_idx');
            $table->index('last_run_id', 'legacy_fixed_asset_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_fixed_asset_records');
    }
};
