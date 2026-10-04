<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table): void {
            $table->string('popup_frequency', 24)->default('once_per_session')->after('delivery_channel');
            $table->string('popup_cta_label_ar', 120)->nullable()->after('popup_frequency');
            $table->string('popup_cta_label_en', 120)->nullable()->after('popup_cta_label_ar');
            $table->string('popup_cta_target', 500)->nullable()->after('popup_cta_label_en');
        });

        Schema::create('notification_campaign_popup_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('viewer_key', 64);
            $table->unsignedInteger('impression_count')->default(0);
            $table->timestamp('first_impression_at')->nullable();
            $table->timestamp('last_impression_at')->nullable();
            $table->timestamp('last_dismissed_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'viewer_key'], 'notification_campaign_popup_view_unique');
            $table->index(['user_id', 'campaign_id'], 'notification_campaign_popup_user_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_popup_views');

        Schema::table('notification_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'popup_frequency',
                'popup_cta_label_ar',
                'popup_cta_label_en',
                'popup_cta_target',
            ]);
        });
    }
};
