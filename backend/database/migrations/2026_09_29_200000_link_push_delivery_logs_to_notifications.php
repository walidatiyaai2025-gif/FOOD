<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_delivery_logs', function (Blueprint $table): void {
            $table->foreignId('notification_id')
                ->nullable()
                ->after('id')
                ->constrained('notifications')
                ->nullOnDelete();
            $table->unsignedSmallInteger('attempt')->default(1)->after('status');
            $table->index(
                ['notification_id', 'device_id', 'status'],
                'push_delivery_notification_device_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('push_delivery_logs', function (Blueprint $table): void {
            $table->dropIndex('push_delivery_notification_device_status_index');
            $table->dropConstrainedForeignId('notification_id');
            $table->dropColumn('attempt');
        });
    }
};
