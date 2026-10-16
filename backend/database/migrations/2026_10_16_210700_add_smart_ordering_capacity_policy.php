<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_branches', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_active_orders')->default(0)->after('max_schedule_days');
            $table->unsignedSmallInteger('capacity_window_minutes')->default(30)->after('max_active_orders');
            $table->unsignedSmallInteger('dine_in_promise_minutes')->default(20)->after('capacity_window_minutes');
            $table->unsignedSmallInteger('pickup_promise_minutes')->default(20)->after('dine_in_promise_minutes');
            $table->unsignedSmallInteger('delivery_promise_minutes')->default(45)->after('pickup_promise_minutes');
        });

        Schema::table('smart_ordering_order_contexts', function (Blueprint $table): void {
            $table->timestamp('promised_for')->nullable()->after('scheduled_for');
            $table->index(
                ['branch_id', 'scheduled_for', 'lifecycle_status'],
                'smart_ordering_order_capacity_window_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('smart_ordering_order_contexts', function (Blueprint $table): void {
            $table->dropIndex('smart_ordering_order_capacity_window_idx');
            $table->dropColumn('promised_for');
        });

        Schema::table('smart_ordering_branches', function (Blueprint $table): void {
            $table->dropColumn([
                'max_active_orders',
                'capacity_window_minutes',
                'dine_in_promise_minutes',
                'pickup_promise_minutes',
                'delivery_promise_minutes',
            ]);
        });
    }
};
