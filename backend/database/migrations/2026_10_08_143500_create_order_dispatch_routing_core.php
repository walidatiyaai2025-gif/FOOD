<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_dispatch_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('status', 40)->default('unrouted');
            $table->foreignId('service_territory_id')->nullable()->constrained('service_territories')->nullOnDelete();
            $table->foreignId('routing_policy_id')->nullable()->constrained('routing_policies')->nullOnDelete();
            $table->foreignId('routing_decision_trace_id')->nullable()->constrained('routing_decision_traces')->nullOnDelete();
            $table->string('routing_mode', 24)->nullable();
            $table->string('routing_source', 64)->nullable();
            $table->string('routing_reason', 128)->nullable();
            $table->string('current_assignee_type', 24)->nullable();
            $table->unsignedBigInteger('current_assignee_id')->nullable();
            $table->string('decision_key', 64)->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'service_territory_id'], 'order_dispatch_queue_lookup');
            $table->index(['current_assignee_type', 'current_assignee_id'], 'order_dispatch_assignee_lookup');
        });

        Schema::create('order_van_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('van_id')->constrained('vans')->restrictOnDelete();
            $table->foreignId('van_assignment_id')->nullable()->constrained('van_assignments')->nullOnDelete();
            $table->foreignId('service_territory_id')->nullable()->constrained('service_territories')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('source', 64);
            $table->string('reason', 128)->nullable();
            $table->string('decision_key', 64)->unique();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->json('routing_context')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'order_van_assignment_active_lookup');
            $table->index(['van_id', 'status'], 'order_van_assignment_van_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_van_assignments');
        Schema::dropIfExists('order_dispatch_states');
    }
};
