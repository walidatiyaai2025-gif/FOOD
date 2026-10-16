<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_workflow_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key', 120);
            $table->unsignedInteger('definition_version');
            $table->string('entity_type', 160);
            $table->string('entity_id', 160);
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('PENDING');
            $table->string('idempotency_key', 160)->unique();
            $table->json('context')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['workflow_key', 'status', 'created_at'],
                'enterprise_workflow_requests_key_status_created_idx',
            );
            $table->index(
                ['scope_type', 'scope_id', 'status'],
                'enterprise_workflow_requests_scope_status_idx',
            );
            $table->index(
                ['entity_type', 'entity_id'],
                'enterprise_workflow_requests_entity_idx',
            );
        });

        Schema::create('enterprise_workflow_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_request_id')
                ->constrained('enterprise_workflow_requests')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('step_code', 100);
            $table->string('required_permission', 160);
            $table->boolean('allow_requester_decision')->default(false);
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workflow_request_id', 'step_code'],
                'enterprise_workflow_steps_request_code_unique',
            );
            $table->unique(
                ['workflow_request_id', 'sequence'],
                'enterprise_workflow_steps_request_sequence_unique',
            );
            $table->index(
                ['status', 'required_permission'],
                'enterprise_workflow_steps_status_permission_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_workflow_steps');
        Schema::dropIfExists('enterprise_workflow_requests');
    }
};
