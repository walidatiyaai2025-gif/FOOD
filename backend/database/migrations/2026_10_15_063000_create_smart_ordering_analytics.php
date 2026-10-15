<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'smart_ordering.analytics.view' => 'View Smart Ordering restaurant analytics',
        'smart_ordering.analytics.export' => 'Export Smart Ordering restaurant analytics',
    ];

    public function up(): void
    {
        Schema::create('smart_ordering_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->nullOnDelete();
            $table->string('event_name', 48)->index();
            $table->char('event_key', 64)->unique();
            $table->char('visitor_hash', 64)->nullable()->index();
            $table->uuid('cart_public_id')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->uuid('qr_public_id')->nullable()->index();
            $table->string('source_snapshot', 64)->nullable()->index();
            $table->string('campaign_snapshot', 120)->nullable()->index();
            $table->string('offer_reference', 120)->nullable()->index();
            $table->string('customer_type', 16)->nullable()->index();
            $table->string('fulfillment_mode', 32)->nullable()->index();
            $table->decimal('revenue', 14, 3)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['store_id', 'occurred_at'], 'so_analytics_store_time_idx');
            $table->index(['store_id', 'event_name', 'occurred_at'], 'so_analytics_funnel_idx');
            $table->index(['store_id', 'branch_id', 'occurred_at'], 'so_analytics_branch_time_idx');
        });

        Schema::create('smart_ordering_analytics_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('event_date');
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->string('event_name', 48);
            $table->string('qr_key', 36)->default('');
            $table->string('source_key', 64)->default('');
            $table->string('campaign_key', 120)->default('');
            $table->string('offer_key', 120)->default('');
            $table->string('customer_type_key', 16)->default('');
            $table->string('fulfillment_mode_key', 32)->default('');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->decimal('revenue', 16, 3)->default(0);
            $table->timestamps();

            $table->unique([
                'event_date', 'store_id', 'branch_key', 'event_name', 'qr_key', 'source_key',
                'campaign_key', 'offer_key', 'customer_type_key', 'fulfillment_mode_key',
            ], 'so_analytics_daily_dimension_unique');
            $table->index(['store_id', 'event_date'], 'so_analytics_daily_store_date_idx');
        });

        Schema::create('smart_ordering_analytics_daily_visitors', function (Blueprint $table): void {
            $table->id();
            $table->date('event_date');
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->string('qr_key', 36)->default('');
            $table->string('source_key', 64)->default('');
            $table->string('campaign_key', 120)->default('');
            $table->char('visitor_hash', 64);
            $table->timestamps();

            $table->unique([
                'event_date', 'store_id', 'branch_key', 'qr_key', 'source_key', 'campaign_key', 'visitor_hash',
            ], 'so_analytics_daily_visitor_unique');
            $table->index(['store_id', 'event_date'], 'so_analytics_visitor_store_date_idx');
        });

        Schema::create('smart_ordering_analytics_product_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('event_date');
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->unsignedBigInteger('product_id');
            $table->string('event_name', 48);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->decimal('revenue', 16, 3)->default(0);
            $table->timestamps();

            $table->unique([
                'event_date', 'store_id', 'branch_key', 'product_id', 'event_name',
            ], 'so_analytics_product_daily_unique');
            $table->index(['store_id', 'event_date', 'product_id'], 'so_analytics_product_store_date_idx');
        });

        Schema::create('smart_ordering_analytics_category_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('event_date');
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->unsignedBigInteger('category_id');
            $table->string('event_name', 48);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->decimal('revenue', 16, 3)->default(0);
            $table->timestamps();

            $table->unique([
                'event_date', 'store_id', 'branch_key', 'category_id', 'event_name',
            ], 'so_analytics_category_daily_unique');
            $table->index(['store_id', 'event_date', 'category_id'], 'so_analytics_category_store_date_idx');
        });

        Schema::create('smart_ordering_analytics_hourly', function (Blueprint $table): void {
            $table->id();
            $table->date('event_date');
            $table->unsignedTinyInteger('event_hour');
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->string('event_name', 48);
            $table->string('qr_key', 36)->default('');
            $table->string('source_key', 64)->default('');
            $table->string('campaign_key', 120)->default('');
            $table->string('fulfillment_mode_key', 32)->default('');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->decimal('revenue', 16, 3)->default(0);
            $table->timestamps();

            $table->unique([
                'event_date', 'event_hour', 'store_id', 'branch_key', 'event_name',
                'qr_key', 'source_key', 'campaign_key', 'fulfillment_mode_key',
            ], 'so_analytics_hourly_dimension_unique');
            $table->index(['store_id', 'event_date', 'event_hour'], 'so_analytics_hour_store_date_idx');
        });

        $now = now();
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $roleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($roleId !== null) {
            $permissionIds = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_analytics_hourly');
        Schema::dropIfExists('smart_ordering_analytics_category_daily');
        Schema::dropIfExists('smart_ordering_analytics_product_daily');
        Schema::dropIfExists('smart_ordering_analytics_daily_visitors');
        Schema::dropIfExists('smart_ordering_analytics_daily');
        Schema::dropIfExists('smart_ordering_analytics_events');

        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
