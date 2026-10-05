<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_current_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id');
            $table->foreignId('van_id')->nullable()->constrained('vans')->nullOnDelete();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->string('route_key')->nullable();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 3)->nullable();
            $table->decimal('speed', 10, 3)->nullable();
            $table->decimal('heading', 8, 3)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->string('source_app', 64)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->timestamps();

            $table->unique(['actor_type', 'actor_id'], 'fleet_location_actor_unique');
            $table->index(['received_at', 'actor_type'], 'fleet_location_freshness_idx');
            $table->index(['latitude', 'longitude'], 'fleet_location_viewport_idx');
            $table->index(['store_id', 'channel'], 'fleet_location_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_current_locations');
    }
};
