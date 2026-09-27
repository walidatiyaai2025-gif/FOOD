<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('type', 64)->default('promotion');
            $table->string('title_ar', 255);
            $table->string('title_en', 255);
            $table->text('body_ar');
            $table->text('body_en');
            $table->string('audience', 32);
            $table->string('app', 32);
            $table->string('target_channel', 16);
            $table->string('delivery_channel', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('schedule_kind', 16)->default('once');
            $table->string('timezone', 64)->default('Asia/Kuwait');
            $table->dateTime('starts_at')->nullable();
            $table->unsignedInteger('interval_value')->nullable();
            $table->string('interval_unit', 16)->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('max_runs')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->dateTime('next_run_at')->nullable()->index();
            $table->dateTime('last_run_at')->nullable();
            $table->foreignId('last_notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->timestamps();

            $table->index(['target_channel', 'store_id', 'status'], 'notification_campaign_scope_status');
        });

        Schema::create('notification_campaign_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->dateTime('scheduled_for');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 16)->default('running');
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'scheduled_for'], 'notification_campaign_run_unique');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_runs');
        Schema::dropIfExists('notification_campaigns');
    }
};
