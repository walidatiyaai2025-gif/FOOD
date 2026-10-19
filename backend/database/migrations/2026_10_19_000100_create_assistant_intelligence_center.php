<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assistant_intelligence_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable();
            $table->string('source_key', 96);
            $table->string('dedupe_key', 160);
            $table->string('severity', 16)->default('info');
            $table->string('status', 24)->default('unread');
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('narrative_ar');
            $table->text('narrative_en');
            $table->json('evidence');
            $table->json('proposed_action')->nullable();
            $table->dateTime('snapshot_at');
            $table->timestamp('stale_after_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recipient_user_id', 'dedupe_key'],
                'assistant_intel_findings_user_dedupe_unique',
            );
            $table->index(
                ['recipient_user_id', 'status', 'snapshot_at'],
                'assistant_intel_findings_recipient_status_idx',
            );
            $table->index(['store_id', 'channel'], 'assistant_intel_findings_scope_idx');
        });

        Schema::create('assistant_intelligence_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 96)->default('global');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('daily_enabled')->default(true);
            $table->boolean('weekly_enabled')->default(true);
            $table->string('minimum_severity', 16)->default('info');
            $table->string('delivery_channel', 24)->default('in_app');
            $table->string('locale', 5)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope_key'], 'assistant_intel_subscriptions_scope_unique');
            $table->index(['enabled', 'daily_enabled', 'weekly_enabled'], 'assistant_intel_subscriptions_due_idx');
        });

        Schema::create('assistant_intelligence_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 96)->default('global');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable();
            $table->string('cadence', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('snapshot_at');
            $table->string('locale', 5);
            $table->string('idempotency_key', 96)->unique();
            $table->json('payload');
            $table->string('state', 24)->default('generated');
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->timestamp('authorization_checked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'cadence', 'snapshot_at'], 'assistant_intel_briefs_user_cadence_idx');
            $table->index(['state', 'created_at'], 'assistant_intel_briefs_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_intelligence_briefs');
        Schema::dropIfExists('assistant_intelligence_subscriptions');
        Schema::dropIfExists('assistant_intelligence_findings');
    }
};
