<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 120)->nullable()->after('name');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $email = strtolower((string) $user->email);
            $base = Str::slug(Str::before($email, '@'), '_');
            $base = $base !== '' ? $base : 'user';
            $candidate = $base;
            $suffix = 1;
            while (DB::table('users')->where('username', $candidate)->where('id', '<>', $user->id)->exists()) {
                $suffix++;
                $candidate = $base.'_'.$suffix;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('username', 'users_username_unique');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('advertising_enabled')->default(true)->after('is_active');
            $table->boolean('coupons_enabled')->default(true)->after('advertising_enabled');
        });

        Schema::create('marketing_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 80);
            $table->string('channel', 8)->index();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('discount_type', 24);
            $table->decimal('discount_value', 14, 3)->default(0);
            $table->decimal('minimum_order_amount', 14, 3)->nullable();
            $table->decimal('maximum_discount_amount', 14, 3)->nullable();
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('first_order_only')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'code'], 'marketing_coupons_scope_code_unique');
            $table->index(['channel', 'store_id', 'is_active'], 'marketing_coupons_scope_active');
        });

        Schema::create('marketing_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('marketing_coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_amount', 14, 3)->default(0);
            $table->decimal('discount_amount', 14, 3)->default(0);
            $table->timestamp('redeemed_at')->useCurrent();
            $table->timestamps();

            $table->index(['coupon_id', 'user_id'], 'marketing_coupon_user_index');
            $table->index(['coupon_id', 'order_id'], 'marketing_coupon_order_index');
        });

        $now = now();
        $abilities = [
            'coupons.view' => 'View coupon administration',
            'coupons.manage' => 'Create, edit, activate and retire coupons',
        ];
        foreach ($abilities as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('code', array_keys($abilities))->pluck('id', 'code');
        $roleIds = DB::table('roles')->whereIn('code', ['B2B_ADMIN', 'B2C_STORE_ADMIN'])->pluck('id', 'code');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_coupon_redemptions');
        Schema::dropIfExists('marketing_coupons');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['advertising_enabled', 'coupons_enabled']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_username_unique');
            $table->dropColumn('username');
        });

        $permissionIds = DB::table('permissions')->whereIn('code', ['coupons.view', 'coupons.manage'])->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
