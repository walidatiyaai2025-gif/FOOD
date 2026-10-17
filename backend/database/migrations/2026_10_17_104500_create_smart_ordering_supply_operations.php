<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_supply_stock_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('foodex_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('on_hand_quantity', 16, 6);
            $table->string('source', 120);
            $table->timestamp('counted_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'branch_id', 'foodex_product_id', 'counted_at'], 'so_supply_snapshot_scope_idx');
        });

        Schema::create('smart_ordering_supply_waste_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('foodex_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 16, 6);
            $table->string('reason', 160);
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'branch_id', 'occurred_at'], 'so_supply_waste_scope_idx');
            $table->index(['site_id', 'foodex_product_id', 'occurred_at'], 'so_supply_waste_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_supply_waste_events');
        Schema::dropIfExists('smart_ordering_supply_stock_snapshots');
    }
};
