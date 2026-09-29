<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('live_ads_enabled')->default(true)->after('advertising_enabled');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('body_en');
        });

        Schema::table('notification_campaigns', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('body_en');
        });

        Schema::table('push_device_tokens', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('push_device_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('install_id', 120)->nullable()->after('user_id');
            $table->foreignId('store_id')->nullable()->after('environment')->constrained('stores')->nullOnDelete();
            $table->string('target_channel', 16)->default('all')->after('store_id');
            $table->string('locale', 8)->default('ar')->after('target_channel');
            $table->timestamp('last_seen_at')->nullable()->after('revoked_at');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['app', 'target_channel', 'store_id', 'revoked_at'], 'push_device_scope_index');
            $table->index(['install_id', 'app'], 'push_device_install_index');
        });

        Schema::create('live_ads', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 80);
            $table->string('channel', 8)->index();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('title_ar', 255);
            $table->string('title_en', 255);
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('image_path')->nullable();
            $table->string('cta_label_ar', 120)->nullable();
            $table->string('cta_label_en', 120)->nullable();
            $table->string('cta_target', 500)->nullable();
            $table->string('frequency', 24)->default('once_per_install');
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'store_id', 'is_active', 'starts_at', 'ends_at'], 'live_ads_scope_window');
            $table->index(['scope_key', 'priority'], 'live_ads_scope_priority');
        });

        $now = now();
        $abilities = [
            'live_ads.view' => 'View live advertising administration',
            'live_ads.manage' => 'Create, edit, schedule and retire live ads',
        ];

        foreach ($abilities as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_keys($abilities))
            ->pluck('id');
        $roleIds = DB::table('roles')
            ->whereIn('code', ['B2B_ADMIN', 'B2C_STORE_ADMIN'])
            ->pluck('id');

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
        Schema::dropIfExists('live_ads');

        Schema::table('push_device_tokens', function (Blueprint $table): void {
            $table->dropIndex('push_device_scope_index');
            $table->dropIndex('push_device_install_index');
            $table->dropConstrainedForeignId('store_id');
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'install_id',
                'target_channel',
                'locale',
                'last_seen_at',
            ]);
        });

        Schema::table('push_device_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('notification_campaigns', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('live_ads_enabled');
        });

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['live_ads.view', 'live_ads.manage'])
            ->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
