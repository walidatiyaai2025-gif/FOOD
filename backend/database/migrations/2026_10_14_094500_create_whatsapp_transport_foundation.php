<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whats_app_connection_profiles', function (Blueprint $table): void {
            $table->text('webhook_verify_token')->nullable()->after('credential_secret');
            $table->text('webhook_signing_secret')->nullable()->after('webhook_verify_token');
        });

        Schema::table('whats_app_conversations', function (Blueprint $table): void {
            $table->string('external_participant_phone', 32)->nullable()->index()->after('platform_customer_id');
        });

        Schema::table('whats_app_messages', function (Blueprint $table): void {
            $table->foreignId('connection_profile_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained('whats_app_connection_profiles');
            $table->string('content_type', 24)->default('text')->after('direction');
            $table->unique(
                ['connection_profile_id', 'provider_message_ref'],
                'wa_messages_profile_provider_ref_unique',
            );
        });

        Schema::create('whats_app_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_profile_id')->constrained('whats_app_connection_profiles')->cascadeOnDelete();
            $table->string('event_hash', 64);
            $table->string('event_type', 64)->nullable();
            $table->json('payload');
            $table->string('status', 24)->default('received')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['connection_profile_id', 'event_hash'], 'wa_webhook_profile_hash_unique');
            $table->index(['status', 'next_retry_at'], 'wa_webhook_retry_index');
        });

        Schema::create('whats_app_outbound_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('whats_app_messages')->cascadeOnDelete();
            $table->foreignId('connection_profile_id')->nullable()->constrained('whats_app_connection_profiles');
            $table->string('idempotency_key', 96)->unique();
            $table->string('recipient', 32);
            $table->json('payload');
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_ref')->nullable()->index();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at'], 'wa_outbound_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_outbound_dispatches');
        Schema::dropIfExists('whats_app_webhook_events');

        Schema::table('whats_app_messages', function (Blueprint $table): void {
            $table->dropUnique('wa_messages_profile_provider_ref_unique');
            $table->dropConstrainedForeignId('connection_profile_id');
            $table->dropColumn('content_type');
        });

        Schema::table('whats_app_conversations', function (Blueprint $table): void {
            $table->dropIndex(['external_participant_phone']);
            $table->dropColumn('external_participant_phone');
        });

        Schema::table('whats_app_connection_profiles', function (Blueprint $table): void {
            $table->dropColumn(['webhook_verify_token', 'webhook_signing_secret']);
        });
    }
};
