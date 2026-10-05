<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_current_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->string('route_key', 128)->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('channel', 16)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->decimal('speed', 10, 2)->nullable();
            $table->decimal('heading', 7, 2)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->string('source_app', 32);
            $table->string('app_version', 64)->nullable();
            $table->boolean('is_mocked')->nullable();
            $table->timestamps();

            $table->unique(['actor_type', 'actor_id'], 'fleet_location_actor_unique');
            $table->index(['store_id', 'channel', 'received_at'], 'fleet_location_scope_received_idx');
            $table->index(['actor_type', 'received_at'], 'fleet_location_actor_received_idx');
            $table->index(['latitude', 'longitude'], 'fleet_location_viewport_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_current_locations');
    }
};
