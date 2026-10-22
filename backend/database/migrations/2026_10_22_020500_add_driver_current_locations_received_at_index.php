<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->index('received_at', 'driver_current_locations_received_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->dropIndex('driver_current_locations_received_at_idx');
        });
    }
};
