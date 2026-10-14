<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_inbound_alerts', function (Blueprint $table): void {
            $table->id();
            // Keep the extension migration independent from the #1403
            // foundation rollback order. Authorization and existence are
            // revalidated at delivery time; these indexed logical references
            // intentionally do not add foreign-key constraints to older tables.
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('state', 16)->default('pending')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['message_id', 'recipient_user_id'],
                'wa_inbound_alert_message_recipient_unique',
            );
            $table->index(
                ['recipient_user_id', 'state', 'id'],
                'wa_inbound_alert_feed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_inbound_alerts');
    }
};
