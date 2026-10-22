<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            ! Schema::hasTable('driver_current_locations')
            || ! Schema::hasColumn('driver_current_locations', 'received_at')
        ) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('driver_current_locations'), 'name');
        if (in_array('driver_current_locations_received_at_idx', $indexes, true)) {
            return;
        }

        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->index('received_at', 'driver_current_locations_received_at_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('driver_current_locations')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('driver_current_locations'), 'name');
        if (! in_array('driver_current_locations_received_at_idx', $indexes, true)) {
            return;
        }

        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->dropIndex('driver_current_locations_received_at_idx');
        });
    }
};
