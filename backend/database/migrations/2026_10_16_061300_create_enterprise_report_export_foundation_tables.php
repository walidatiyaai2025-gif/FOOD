<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_report_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_code', 120);
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('scheduled_report_id')->nullable();
            $table->json('query_payload');
            $table->unsignedBigInteger('row_count');
            $table->string('execution_mode', 32)->default('queued_chunked');
            $table->unsignedInteger('chunk_size');
            $table->unsignedBigInteger('next_offset')->default(0);
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedInteger('chunks_completed')->default(0);
            $table->string('status', 32)->default('queued');
            $table->string('failure_code', 64)->nullable();
            $table->json('artifact_metadata')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'enterprise_report_exports_scope_idx');
            $table->index(['status', 'updated_at'], 'enterprise_report_exports_status_idx');
            $table->index('scheduled_report_id', 'enterprise_report_exports_schedule_idx');
        });

        Schema::create('enterprise_scheduled_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_code', 120);
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->json('query_payload');
            $table->unsignedBigInteger('row_count_estimate');
            $table->unsignedInteger('interval_minutes');
            $table->timestamp('next_run_at');
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('last_error', 64)->nullable();
            $table->string('schedule_key', 64)->unique();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at'], 'enterprise_scheduled_reports_due_idx');
            $table->index(['scope_type', 'scope_id'], 'enterprise_scheduled_reports_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_scheduled_reports');
        Schema::dropIfExists('enterprise_report_exports');
    }
};
