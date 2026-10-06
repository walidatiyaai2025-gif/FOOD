<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code')->unique();
            $table->string('plate_number')->nullable()->unique();
            $table->string('vehicle_type')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('capacity_units')->nullable();
            $table->decimal('capacity_weight', 12, 3)->nullable();
            $table->json('capabilities')->nullable();
            $table->foreignId('home_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('van_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('van_id')->constrained('vans')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('representative_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('territory_key')->nullable();
            $table->string('van_pool_key')->nullable();
            $table->string('assignment_type')->default('primary');
            $table->string('status')->default('active');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->unsignedInteger('loaded_work_count')->default(0);
            $table->foreignId('transferred_to_van_id')->nullable()->constrained('vans')->nullOnDelete();
            $table->text('transfer_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['van_id', 'status', 'effective_from', 'effective_until'], 'van_assignment_effective_lookup');
            $table->index(['driver_id', 'status', 'effective_from', 'effective_until'], 'van_assignment_driver_lookup');
            $table->index(['territory_key', 'warehouse_id', 'van_pool_key'], 'van_assignment_eligibility_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_assignments');
        Schema::dropIfExists('vans');
    }
};
