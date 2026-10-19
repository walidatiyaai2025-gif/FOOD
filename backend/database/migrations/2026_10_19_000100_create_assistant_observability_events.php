<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_observability_events', function (Blueprint $table): void {
            $table->id();
            $table->string('trace_id', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('event_name', 80);
            $table->string('phase', 32)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('tool_key', 160)->nullable();
            $table->string('outcome', 80)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('fallback')->default(false);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['trace_id', 'created_at'], 'assistant_obs_trace_created_idx');
            $table->index(['phase', 'created_at'], 'assistant_obs_phase_created_idx');
            $table->index(['outcome', 'created_at'], 'assistant_obs_outcome_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'assistant_obs_actor_created_idx');
            $table->index(['store_id', 'created_at'], 'assistant_obs_store_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_observability_events');
    }
};
