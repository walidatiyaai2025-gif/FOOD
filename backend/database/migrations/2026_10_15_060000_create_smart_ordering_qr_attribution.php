<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'smart_ordering.qr.view' => 'View Smart Ordering QR codes and attribution metrics',
        'smart_ordering.qr.manage' => 'Create, edit, rotate and disable Smart Ordering QR codes',
    ];

    public function up(): void
    {
        Schema::create('smart_ordering_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->string('label', 160);
            $table->text('notes')->nullable();
            $table->string('target_path', 1024);
            $table->string('source', 64)->nullable()->index();
            $table->string('medium', 64)->nullable();
            $table->string('campaign', 120)->nullable()->index();
            $table->string('branch_reference', 120)->nullable();
            $table->string('table_reference', 120)->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'type', 'is_enabled'], 'so_qr_store_type_enabled_idx');
        });

        Schema::create('smart_ordering_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_code_id')->constrained('smart_ordering_qr_codes')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_value');
            $table->string('state', 16)->default('active')->index();
            $table->timestamp('legacy_expires_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['qr_code_id', 'state'], 'so_qr_token_state_idx');
        });

        Schema::create('smart_ordering_qr_visits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('qr_code_id')->constrained('smart_ordering_qr_codes')->cascadeOnDelete();
            $table->foreignId('qr_token_id')->constrained('smart_ordering_qr_tokens')->cascadeOnDelete();
            $table->char('visitor_hash', 64)->index();
            $table->string('source_snapshot', 64)->nullable()->index();
            $table->string('medium_snapshot', 64)->nullable();
            $table->string('campaign_snapshot', 120)->nullable()->index();
            $table->string('branch_reference_snapshot', 120)->nullable();
            $table->string('table_reference_snapshot', 120)->nullable();
            $table->string('referrer_snapshot', 1024)->nullable();
            $table->timestamp('landing_at')->index();
            $table->timestamp('storefront_viewed_at')->nullable();
            $table->timestamp('cart_started_at')->nullable();
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('revenue', 14, 3)->default(0);
            $table->timestamps();

            $table->unique('cart_id', 'so_qr_visit_cart_unique');
            $table->unique('order_id', 'so_qr_visit_order_unique');
            $table->index(['qr_code_id', 'landing_at'], 'so_qr_visit_scan_idx');
        });

        Schema::create('smart_ordering_qr_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_visit_id')->constrained('smart_ordering_qr_visits')->cascadeOnDelete();
            $table->string('event_name', 48)->index();
            $table->char('event_key', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['qr_visit_id', 'event_name'], 'so_qr_event_funnel_idx');
        });

        $now = now();
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $roleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($roleId !== null) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', array_keys(self::PERMISSIONS))
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_qr_events');
        Schema::dropIfExists('smart_ordering_qr_visits');
        Schema::dropIfExists('smart_ordering_qr_tokens');
        Schema::dropIfExists('smart_ordering_qr_codes');

        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
