<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assistant_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 24)->default('scheduler');
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->unsignedInteger('failed_chunks')->default(0);
            $table->decimal('coverage_percent', 5, 2)->default(0);
            $table->string('agent_source', 24)->default('DATABASE');
            $table->json('agent_summary')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('agent_latency_ms')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_progress_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'assistant_analysis_runs_status_idx');
        });

        Schema::create('assistant_analysis_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('assistant_analysis_runs')->cascadeOnDelete();
            $table->string('batch_key', 80);
            $table->string('domain', 32);
            $table->json('store_ids');
            $table->string('status', 24)->default('pending');
            $table->string('source_kind', 24)->default('DATABASE');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->json('metrics')->nullable();
            $table->json('agent_opinion')->nullable();
            $table->json('evidence')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('agent_latency_ms')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'batch_key'], 'assistant_analysis_chunks_run_batch_unique');
            $table->index(['run_id', 'status', 'id'], 'assistant_analysis_chunks_run_status_idx');
            $table->index(['domain', 'status'], 'assistant_analysis_chunks_domain_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_analysis_chunks');
        Schema::dropIfExists('assistant_analysis_runs');
    }
};
