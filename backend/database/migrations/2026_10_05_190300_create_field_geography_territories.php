<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geography_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->string('country_code', 2)->index();
            $table->string('level', 32)->index();
            $table->string('code', 96);
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['country_code', 'level', 'code'], 'geo_nodes_country_level_code_unique');
        });

        Schema::create('service_territories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('geography_node_id')->nullable()->constrained('geography_nodes')->nullOnDelete();
            $table->string('country_code', 2)->index();
            $table->string('code', 96)->unique();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->json('geometry');
            $table->string('status', 24)->default('active')->index();
            $table->integer('priority')->default(0)->index();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('service_calendar_code', 96)->nullable();
            $table->json('tags')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_until')->nullable()->index();
            $table->timestamps();
            $table->index(['country_code', 'status', 'priority'], 'territory_country_status_priority_idx');
        });

        Schema::create('address_territory_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('service_territory_id')->constrained('service_territories')->cascadeOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->string('reason')->nullable();
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_until')->nullable()->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['address_id', 'status'], 'address_territory_override_status_idx');
        });

        Schema::create('territory_admin_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2)->index();
            $table->string('city')->nullable()->index();
            $table->string('area')->nullable()->index();
            $table->foreignId('service_territory_id')->constrained('service_territories')->cascadeOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->integer('priority')->default(0);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['country_code', 'status', 'priority'], 'territory_admin_mapping_lookup_idx');
        });

        Schema::create('territory_resolution_traces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();
            $table->string('source', 32)->index();
            $table->json('candidate_territory_ids')->nullable();
            $table->foreignId('chosen_service_territory_id')->nullable()->constrained('service_territories')->nullOnDelete();
            $table->string('chosen_reason')->nullable();
            $table->json('decision_trace');
            $table->timestamp('resolved_at')->index();
            $table->timestamps();
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->foreignId('resolved_service_territory_id')
                ->nullable()
                ->after('longitude')
                ->constrained('service_territories')
                ->nullOnDelete();
            $table->string('territory_resolution_source', 32)->nullable()->after('resolved_service_territory_id');
            $table->timestamp('territory_resolved_at')->nullable()->after('territory_resolution_source');
            $table->string('serviceability_status', 32)->default('unresolved')->after('territory_resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_service_territory_id');
            $table->dropColumn([
                'territory_resolution_source',
                'territory_resolved_at',
                'serviceability_status',
            ]);
        });

        Schema::dropIfExists('territory_resolution_traces');
        Schema::dropIfExists('territory_admin_mappings');
        Schema::dropIfExists('address_territory_overrides');
        Schema::dropIfExists('service_territories');
        Schema::dropIfExists('geography_nodes');
    }
};
