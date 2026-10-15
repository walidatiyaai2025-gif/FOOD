<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_branches', function (Blueprint $table): void {
            $table->boolean('accepting_orders')->default(true)->after('is_active');
            $table->boolean('supports_dine_in')->default(true)->after('accepting_orders');
            $table->boolean('supports_pickup')->default(true)->after('supports_dine_in');
            $table->boolean('supports_delivery')->default(true)->after('supports_pickup');
            $table->boolean('allow_scheduled')->default(false)->after('supports_delivery');
            $table->unsignedSmallInteger('pickup_lead_minutes')->default(15)->after('allow_scheduled');
            $table->unsignedSmallInteger('max_schedule_days')->default(7)->after('pickup_lead_minutes');
            $table->decimal('service_charge', 14, 3)->default(0)->after('max_schedule_days');
            $table->string('whatsapp_routing_identity')->nullable()->after('service_charge');
            $table->json('holiday_closed_dates')->nullable()->after('whatsapp_routing_identity');
            $table->index(
                ['site_id', 'is_active', 'accepting_orders'],
                'smart_ordering_branch_operational_idx',
            );
        });

        Schema::create('smart_ordering_branch_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'day_of_week'], 'smart_ordering_branch_day_unique');
        });

        Schema::create('smart_ordering_delivery_zones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('center_latitude', 10, 7);
            $table->decimal('center_longitude', 10, 7);
            $table->decimal('radius_km', 10, 3);
            $table->decimal('delivery_fee', 14, 3)->default(0);
            $table->decimal('minimum_order', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active'], 'smart_ordering_zone_active_idx');
        });

        Schema::create('smart_ordering_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('label');
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active'], 'smart_ordering_table_active_idx');
        });

        Schema::create('smart_ordering_carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('token_hash', 64);
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->uuid('revision_public_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('fulfillment_mode', 30)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'expires_at'], 'smart_ordering_cart_operational_idx');
        });

        Schema::create('smart_ordering_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cart_id')->constrained('smart_ordering_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->json('selection')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['cart_id', 'product_id'], 'smart_ordering_cart_product_idx');
        });

        Schema::create('smart_ordering_order_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->uuid('public_reference')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->restrictOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained('smart_ordering_carts')->nullOnDelete();
            $table->uuid('revision_public_id')->nullable();
            $table->string('fulfillment_mode', 30);
            $table->foreignId('table_id')->nullable()->constrained('smart_ordering_tables')->nullOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('smart_ordering_delivery_zones')->nullOnDelete();
            $table->timestamp('scheduled_for')->nullable();
            $table->json('delivery_address')->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->decimal('subtotal', 14, 3)->default(0);
            $table->decimal('discount_total', 14, 3)->default(0);
            $table->decimal('delivery_total', 14, 3)->default(0);
            $table->decimal('service_charge_total', 14, 3)->default(0);
            $table->decimal('tax_total', 14, 3)->default(0);
            $table->decimal('grand_total', 14, 3)->default(0);
            $table->string('lifecycle_status', 30)->default('NEW');
            $table->string('idempotency_key_hash', 64)->unique();
            $table->string('request_hash', 64);
            $table->json('cart_snapshot')->nullable();
            $table->string('notification_status', 30)->default('pending');
            $table->text('notification_error')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'lifecycle_status', 'created_at'], 'smart_ordering_order_queue_idx');
            $table->index(['fulfillment_mode', 'created_at'], 'smart_ordering_order_mode_idx');
        });

        Schema::create('smart_ordering_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_context_id')->constrained('smart_ordering_order_contexts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event_key', 100)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['order_context_id', 'event_key'], 'smart_ordering_event_idempotency_unique');
            $table->index(['order_context_id', 'created_at'], 'smart_ordering_event_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_order_events');
        Schema::dropIfExists('smart_ordering_order_contexts');
        Schema::dropIfExists('smart_ordering_cart_items');
        Schema::dropIfExists('smart_ordering_carts');
        Schema::dropIfExists('smart_ordering_tables');
        Schema::dropIfExists('smart_ordering_delivery_zones');
        Schema::dropIfExists('smart_ordering_branch_hours');

        Schema::table('smart_ordering_branches', function (Blueprint $table): void {
            $table->dropIndex('smart_ordering_branch_operational_idx');
            $table->dropColumn([
                'accepting_orders',
                'supports_dine_in',
                'supports_pickup',
                'supports_delivery',
                'allow_scheduled',
                'pickup_lead_minutes',
                'max_schedule_days',
                'service_charge',
                'whatsapp_routing_identity',
                'holiday_closed_dates',
            ]);
        });
    }
};
