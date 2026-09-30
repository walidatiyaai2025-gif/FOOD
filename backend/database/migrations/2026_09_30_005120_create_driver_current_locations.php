<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_current_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('channel', 8);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 9, 2)->nullable();
            $table->decimal('speed', 9, 2)->nullable();
            $table->decimal('heading', 7, 2)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->string('app_version', 64)->nullable();
            $table->boolean('is_mocked')->nullable();
            $table->foreignId('active_assignment_id')->nullable()->constrained('driver_assignments')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'channel', 'captured_at'], 'driver_current_locations_scope_idx');
            $table->index(['channel', 'received_at'], 'driver_current_locations_freshness_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_current_locations');
    }
};
