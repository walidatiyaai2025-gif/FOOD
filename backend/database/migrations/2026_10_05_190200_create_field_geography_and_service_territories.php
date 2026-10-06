<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('geography_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->string('type', 32);
            $table->string('code', 96);
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('country_code', 3);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'type', 'code'], 'geography_nodes_country_type_code_unique');
            $table->index(['parent_id', 'type', 'is_active'], 'geography_nodes_parent_type_active');
        });

        Schema::create('service_territories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->foreignId('country_node_id')->constrained('geography_nodes');
            $table->foreignId('governorate_node_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->foreignId('city_node_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->foreignId('district_node_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->json('service_calendar')->nullable();
            $table->json('tags')->nullable();
            $table->json('capabilities')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['country_node_id', 'status', 'effective_from', 'effective_until'], 'service_territories_effective');
            $table->index(['priority', 'status'], 'service_territories_priority_status');
        });

        Schema::create('territory_geometries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_territory_id')->constrained('service_territories')->cascadeOnDelete();
            $table->string('geometry_type', 24);
            $table->json('geojson');
            $table->decimal('min_lat', 10, 7)->nullable();
            $table->decimal('max_lat', 10, 7)->nullable();
            $table->decimal('min_lng', 10, 7)->nullable();
            $table->decimal('max_lng', 10, 7)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'min_lat', 'max_lat', 'min_lng', 'max_lng'], 'territory_geometry_bbox');
            $table->unique(['service_territory_id', 'version'], 'territory_geometry_version_unique');
        });

        Schema::create('address_territory_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->string('address_type', 64);
            $table->string('address_key', 128);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('service_territory_id')->nullable()->constrained('service_territories')->nullOnDelete();
            $table->string('source', 40);
            $table->string('serviceability_status', 32);
            $table->json('candidate_territory_ids')->nullable();
            $table->text('chosen_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->timestamp('override_expires_at')->nullable();
            $table->json('trace')->nullable();
            $table->timestamps();

            $table->index(['address_type', 'address_key', 'resolved_at'], 'address_territory_resolution_lookup');
            $table->index(['service_territory_id', 'serviceability_status'], 'address_territory_serviceability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_territory_resolutions');
        Schema::dropIfExists('territory_geometries');
        Schema::dropIfExists('service_territories');
        Schema::dropIfExists('geography_nodes');
    }
};
