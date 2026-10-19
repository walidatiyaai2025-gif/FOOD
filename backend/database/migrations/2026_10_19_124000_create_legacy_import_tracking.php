<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_import_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('phase', 64);
            $table->string('mode', 16);
            $table->string('status', 24)->default('running');
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'phase', 'status'], 'legacy_import_runs_lookup_idx');
        });

        Schema::create('legacy_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('source_table', 128);
            $table->string('source_key', 190);
            $table->string('target_table', 128);
            $table->unsignedBigInteger('target_id');
            $table->char('source_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_table', 'source_key'],
                'legacy_import_mapping_source_uq',
            );
            $table->index(
                ['target_table', 'target_id'],
                'legacy_import_mapping_target_idx',
            );
            $table->index('last_run_id', 'legacy_import_mapping_run_idx');
        });

        Schema::create('legacy_import_quarantine', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('source_table', 128);
            $table->string('source_key', 190);
            $table->string('reason', 190);
            $table->json('payload')->nullable();
            $table->uuid('run_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_table', 'source_key', 'reason'],
                'legacy_import_quarantine_source_reason_uq',
            );
            $table->index(['run_id', 'resolved_at'], 'legacy_import_quarantine_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_quarantine');
        Schema::dropIfExists('legacy_import_mappings');
        Schema::dropIfExists('legacy_import_runs');
    }
};
