<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('smart_ordering_tables')->nullOnDelete();
            $table->string('guest_name', 160);
            $table->string('guest_phone', 60)->nullable();
            $table->string('guest_email', 190)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('service_at');
            $table->string('status', 32)->default('BOOKED');
            $table->text('notes')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'service_at', 'status'], 'so_reservation_branch_service_idx');
            $table->index(['branch_id', 'table_id', 'service_at'], 'so_reservation_table_service_idx');
            $table->index(['branch_id', 'guest_phone'], 'so_reservation_phone_idx');
        });

        Schema::create('smart_ordering_waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('smart_ordering_tables')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('smart_ordering_reservations')->nullOnDelete();
            $table->string('guest_name', 160);
            $table->string('guest_phone', 60)->nullable();
            $table->string('guest_email', 190)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('arrived_at');
            $table->unsignedSmallInteger('quoted_wait_minutes')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('priority_reason', 255)->nullable();
            $table->string('status', 32)->default('WAITING');
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'arrived_at'], 'so_waitlist_branch_status_idx');
            $table->index(['branch_id', 'guest_phone'], 'so_waitlist_phone_idx');
        });

        $now = now();
        foreach (DB::table('smart_ordering_sites')->pluck('id') as $siteId) {
            DB::table('smart_ordering_entitlements')->insertOrIgnore([
                'site_id' => (int) $siteId,
                'branch_id' => null,
                'scope_key' => 'site',
                'module' => 'reservations',
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('smart_ordering_entitlements')
            ->where('module', 'reservations')
            ->delete();

        Schema::dropIfExists('smart_ordering_waitlist_entries');
        Schema::dropIfExists('smart_ordering_reservations');
    }
};
