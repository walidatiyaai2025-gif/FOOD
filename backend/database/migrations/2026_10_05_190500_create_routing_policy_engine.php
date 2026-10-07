<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('routing_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code');
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->string('mode')->default('MANUAL');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('previous_policy_id')->nullable()->constrained('routing_policies')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['code', 'status', 'effective_from', 'effective_until'], 'routing_policy_effective_lookup');
        });

        Schema::create('routing_policy_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('routing_policy_id')->constrained('routing_policies')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['routing_policy_id', 'position']);
        });

        Schema::create('routing_decision_traces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('routing_policy_id')->constrained('routing_policies')->restrictOnDelete();
            $table->string('subject_type');
            $table->string('subject_key');
            $table->json('input_snapshot');
            $table->json('result');
            $table->json('evaluated_rules');
            $table->string('routing_mode');
            $table->json('mode_resolution');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['subject_type', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_decision_traces');
        Schema::dropIfExists('routing_policy_rules');
        Schema::dropIfExists('routing_policies');
    }
};
