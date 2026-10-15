<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_smart_ordering_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_context_id')->unique()->constrained('smart_ordering_order_contexts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->uuid('public_reference')->unique();
            $table->string('fulfillment_mode', 30);
            $table->string('lifecycle_status', 30)->index();
            $table->json('snapshot');
            $table->string('last_event_name', 64)->nullable();
            $table->string('last_event_status', 64)->nullable()->index();
            $table->unsignedBigInteger('last_transactional_event_id')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'updated_at'], 'wa_so_conversation_updated_idx');
            $table->index(['platform_customer_id', 'store_id', 'updated_at'], 'wa_so_customer_store_updated_idx');
        });

        $now = now();
        foreach (['order.ready_for_pickup', 'order.scheduled_reminder'] as $eventName) {
            DB::table('whats_app_transactional_preferences')->insertOrIgnore([
                'store_id' => 0,
                'customer_channel' => 'b2c',
                'delivery_channel' => 'whatsapp',
                'event_name' => $eventName,
                'enabled' => false,
                'template_name_ar' => null,
                'template_name_en' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_smart_ordering_links');

        DB::table('whats_app_transactional_preferences')
            ->where('store_id', 0)
            ->where('customer_channel', 'b2c')
            ->where('delivery_channel', 'whatsapp')
            ->whereIn('event_name', ['order.ready_for_pickup', 'order.scheduled_reminder'])
            ->delete();
    }
};
