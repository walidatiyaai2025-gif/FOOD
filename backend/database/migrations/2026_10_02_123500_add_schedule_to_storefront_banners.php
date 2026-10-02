<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable()->after('is_active');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->index(
                ['store_id', 'target_type', 'is_active', 'starts_at', 'ends_at'],
                'banners_platform_schedule_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->dropIndex('banners_platform_schedule_idx');
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
