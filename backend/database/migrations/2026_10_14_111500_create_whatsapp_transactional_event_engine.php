<?php

use App\Domain\WhatsApp\TransactionalNotificationEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_transactional_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('store_id')->default(0)->index();
            $table->string('customer_channel', 16)->default('*');
            $table->string('delivery_channel', 24)->default('whatsapp');
            $table->string('event_name', 64);
            $table->boolean('enabled')->default(false);
            $table->string('template_name_ar', 128)->nullable();
            $table->string('template_name_en', 128)->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'customer_channel', 'delivery_channel', 'event_name'],
                'wa_tx_pref_scope_unique',
            );
            $table->index(
                ['event_name', 'delivery_channel', 'enabled'],
                'wa_tx_pref_event_enabled_index',
            );
        });

        Schema::create('whats_app_transactional_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name', 64)->index();
            $table->string('event_key', 191);
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('customer_channel', 16);
            $table->string('delivery_channel', 24)->default('whatsapp');
            $table->unsignedBigInteger('platform_customer_id')->nullable()->index();
            $table->string('recipient', 32)->nullable();
            $table->string('locale', 8)->default('en');
            $table->json('payload');
            $table->string('template_name', 128);
            $table->string('status', 64)->default('queued')->index();
            $table->unsignedBigInteger('message_id')->nullable()->unique();
            $table->unsignedBigInteger('dispatch_id')->nullable()->index();
            $table->string('skipped_reason', 64)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['store_id', 'customer_channel', 'event_name', 'status'],
                'wa_tx_event_scope_status_index',
            );
        });

        $now = now();
        DB::table('whats_app_transactional_preferences')->insert(
            array_map(
                static fn (string $event): array => [
                    'store_id' => 0,
                    'customer_channel' => '*',
                    'delivery_channel' => 'whatsapp',
                    'event_name' => $event,
                    'enabled' => false,
                    'template_name_ar' => null,
                    'template_name_en' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                TransactionalNotificationEngine::EVENTS,
            ),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_transactional_events');
        Schema::dropIfExists('whats_app_transactional_preferences');
    }
};
