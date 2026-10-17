<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_guest_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->char('identity_hash', 64);
            $table->string('display_name', 160);
            $table->string('phone', 64)->nullable();
            $table->string('email', 190)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'identity_hash'], 'so_guest_site_identity_unique');
            $table->index(['site_id', 'customer_id'], 'so_guest_site_customer_idx');
            $table->index(['site_id', 'last_seen_at'], 'so_guest_site_seen_idx');
        });

        Schema::create('smart_ordering_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('guest_profile_id')->constrained('smart_ordering_guest_profiles')->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('smart_ordering_tables')->nullOnDelete();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('service_at');
            $table->string('status', 24)->default('BOOKED');
            $table->string('source', 40)->default('dashboard');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'service_at', 'status'], 'so_res_branch_service_status_idx');
            $table->index(['table_id', 'service_at', 'status'], 'so_res_table_service_status_idx');
        });

        Schema::create('smart_ordering_waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('guest_profile_id')->constrained('smart_ordering_guest_profiles')->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('smart_ordering_tables')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('smart_ordering_reservations')->nullOnDelete();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('arrived_at');
            $table->unsignedSmallInteger('quoted_wait_minutes')->default(0);
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('priority_reason', 160)->nullable();
            $table->string('status', 24)->default('WAITING');
            $table->string('conversion_key', 160)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'priority', 'arrived_at'], 'so_wait_branch_queue_idx');
        });

        $now = now();
        $rows = DB::table('smart_ordering_sites')->pluck('id')->map(static fn ($siteId): array => [
            'site_id' => (int) $siteId,
            'branch_id' => null,
            'scope_key' => 'site',
            'module' => 'reservations',
            'enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('smart_ordering_entitlements')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_waitlist_entries');
        Schema::dropIfExists('smart_ordering_reservations');
        Schema::dropIfExists('smart_ordering_guest_profiles');

        DB::table('smart_ordering_entitlements')
            ->where('module', 'reservations')
            ->delete();
    }
};
