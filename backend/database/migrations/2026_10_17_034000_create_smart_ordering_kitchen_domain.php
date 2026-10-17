<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_kitchen_stations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('name_en', 160);
            $table->string('name_ar', 160)->nullable();
            $table->unsignedSmallInteger('capacity_limit')->default(20);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active', 'sort_order'], 'so_kitchen_station_branch_active_idx');
        });

        Schema::create('smart_ordering_kitchen_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('smart_ordering_kitchen_stations')->cascadeOnDelete();
            $table->string('match_type', 20);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active', 'priority'], 'so_kitchen_rule_branch_active_idx');
        });

        Schema::create('smart_ordering_kitchen_ticket_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_context_id')->unique()->constrained('smart_ordering_order_contexts')->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->timestamp('expedited_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('smart_ordering_kitchen_item_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('ticket_state_id')->constrained('smart_ordering_kitchen_ticket_states')->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ticket_state_id', 'status'], 'so_kitchen_item_ticket_status_idx');
        });

        Schema::create('smart_ordering_kitchen_ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_state_id')->constrained('smart_ordering_kitchen_ticket_states')->cascadeOnDelete();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ticket_state_id', 'created_at'], 'so_kitchen_ticket_event_idx');
        });

        if (Schema::hasTable('smart_ordering_sites') && Schema::hasTable('smart_ordering_entitlements')) {
            $now = now();
            foreach (DB::table('smart_ordering_sites')->pluck('id') as $siteId) {
                foreach (['kitchen_display', 'kitchen_routing'] as $module) {
                    DB::table('smart_ordering_entitlements')->updateOrInsert(
                        [
                            'site_id' => (int) $siteId,
                            'branch_id' => null,
                            'scope_key' => 'site',
                            'module' => $module,
                        ],
                        ['enabled' => false, 'updated_at' => $now, 'created_at' => $now],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('smart_ordering_entitlements')) {
            DB::table('smart_ordering_entitlements')
                ->whereIn('module', ['kitchen_display', 'kitchen_routing'])
                ->delete();
        }

        Schema::dropIfExists('smart_ordering_kitchen_ticket_events');
        Schema::dropIfExists('smart_ordering_kitchen_item_states');
        Schema::dropIfExists('smart_ordering_kitchen_ticket_states');
        Schema::dropIfExists('smart_ordering_kitchen_routing_rules');
        Schema::dropIfExists('smart_ordering_kitchen_stations');
    }
};
