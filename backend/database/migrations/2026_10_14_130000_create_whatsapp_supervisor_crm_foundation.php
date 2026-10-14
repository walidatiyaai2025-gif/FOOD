<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_customer_id')->unique()->constrained('platform_customers')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('temperature', 16)->default('cold')->index();
            $table->string('status', 24)->default('open')->index();
            $table->string('source_type', 64)->default('manual')->index();
            $table->string('source_id', 191)->nullable()->index();
            $table->string('source_label', 191)->nullable();
            $table->timestamp('opened_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'temperature', 'status'], 'wa_leads_store_temp_status_idx');
            $table->index(['assigned_agent_id', 'status'], 'wa_leads_agent_status_idx');
        });

        Schema::create('whats_app_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('whats_app_leads')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 191);
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('source_type', 64)->default('manual')->index();
            $table->string('source_id', 191)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status', 'due_at'], 'wa_followups_store_status_due_idx');
            $table->index(['assigned_agent_id', 'status', 'due_at'], 'wa_followups_agent_status_due_idx');
        });

        Schema::create('whats_app_conversation_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('whats_app_conversations')->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('outcome_code', 64)->index();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['store_id', 'outcome_code'], 'wa_outcomes_store_code_idx');
        });

        Schema::create('whats_app_crm_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64)->index();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 64)->nullable()->index();
            $table->string('source_id', 191)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['store_id', 'occurred_at'], 'wa_crm_events_store_time_idx');
            $table->index(['platform_customer_id', 'occurred_at'], 'wa_crm_events_customer_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_crm_events');
        Schema::dropIfExists('whats_app_conversation_outcomes');
        Schema::dropIfExists('whats_app_follow_ups');
        Schema::dropIfExists('whats_app_leads');
    }
};
