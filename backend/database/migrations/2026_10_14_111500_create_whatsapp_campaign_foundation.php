<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('provider_name', 160);
            $table->string('locale', 16)->default('en');
            $table->string('category', 32)->default('marketing');
            $table->string('provider_status', 24)->default('pending')->index();
            $table->text('body_preview')->nullable();
            $table->json('components')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider_name', 'locale'], 'wa_template_provider_locale_unique');
        });

        Schema::create('whats_app_marketing_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->string('phone', 32)->index();
            $table->string('state', 24)->index();
            $table->string('source', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['platform_customer_id', 'occurred_at'], 'wa_consent_customer_time_index');
        });

        Schema::create('whats_app_marketing_preferences', function (Blueprint $table): void {
            $table->foreignId('platform_customer_id')->primary()->constrained('platform_customers')->cascadeOnDelete();
            $table->string('phone', 32)->index();
            $table->string('state', 24)->default('unknown')->index();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->unsignedBigInteger('last_consent_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('whats_app_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('template_id')->constrained('whats_app_templates')->restrictOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->json('segment');
            $table->boolean('test_mode')->default(true)->index();
            $table->json('test_allowlist')->nullable();
            $table->unsignedInteger('batch_size')->default(50);
            $table->unsignedInteger('per_minute_limit')->default(100);
            $table->unsignedInteger('max_recipients')->default(1000);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_at'], 'wa_campaign_due_index');
        });

        Schema::create('whats_app_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('whats_app_campaigns')->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->string('phone', 32);
            $table->json('snapshot');
            $table->string('eligibility_status', 24)->default('eligible')->index();
            $table->string('suppression_reason', 64)->nullable()->index();
            $table->string('send_status', 24)->default('pending')->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'platform_customer_id'], 'wa_campaign_recipient_customer_unique');
            $table->index(['campaign_id', 'send_status'], 'wa_campaign_recipient_status_index');
        });

        Schema::create('whats_app_campaign_followups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_recipient_id')->unique()->constrained('whats_app_campaign_recipients')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('open')->index();
            $table->string('reason', 64)->default('campaign_reply');
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        foreach ([
            'whatsapp.campaigns.view' => 'View WhatsApp campaigns and marketing metrics',
            'whatsapp.campaigns.manage' => 'Manage WhatsApp templates, consent, segments and campaigns',
            'whatsapp.campaigns.send' => 'Schedule, pause, cancel and dispatch WhatsApp campaigns',
        ] as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $roleId = DB::table('roles')->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->value('id');
        if ($roleId !== null) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', [
                    'whatsapp.campaigns.view',
                    'whatsapp.campaigns.manage',
                    'whatsapp.campaigns.send',
                ])
                ->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_campaign_followups');
        Schema::dropIfExists('whats_app_campaign_recipients');
        Schema::dropIfExists('whats_app_campaigns');
        Schema::dropIfExists('whats_app_marketing_preferences');
        Schema::dropIfExists('whats_app_marketing_consents');
        Schema::dropIfExists('whats_app_templates');
    }
};
