<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
            $table->string('scope', 20)->default('global')->after('store_id');
            $table->string('scope_key', 80)->default('global')->after('scope');
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->boolean('is_active')->default(true)->after('name_en');
        });

        Schema::table('units', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable()->after('id');
            $table->string('scope', 20)->default('global')->after('store_id');
            $table->string('scope_key', 80)->default('global')->after('scope');
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->boolean('is_active')->default(true)->after('name_en');
        });

        DB::table('brands')->update([
            'scope' => 'global',
            'scope_key' => 'global',
            'name_ar' => DB::raw('name'),
            'name_en' => DB::raw('name'),
            'is_active' => true,
        ]);
        DB::table('units')->update([
            'scope' => 'global',
            'scope_key' => 'global',
            'name_ar' => DB::raw('name'),
            'name_en' => DB::raw('name'),
            'is_active' => true,
        ]);

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_slug_unique');
            $table->foreign('store_id', 'brands_store_fk')->references('id')->on('stores')->restrictOnDelete();
            $table->unique(['scope_key', 'slug'], 'brands_scope_slug_unique');
            $table->index(['scope', 'store_id', 'is_active'], 'brands_scope_store_active_idx');
        });
        Schema::table('units', function (Blueprint $table): void {
            $table->dropUnique('units_code_unique');
            $table->foreign('store_id', 'units_store_fk')->references('id')->on('stores')->restrictOnDelete();
            $table->unique(['scope_key', 'code'], 'units_scope_code_unique');
            $table->index(['scope', 'store_id', 'is_active'], 'units_scope_store_active_idx');
        });

        $this->installPermissions();
    }

    public function down(): void
    {
        $brandDuplicates = DB::table('brands')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $unitDuplicates = DB::table('units')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropForeign('brands_store_fk');
            $table->dropUnique('brands_scope_slug_unique');
            $table->dropIndex('brands_scope_store_active_idx');
            $table->dropColumn(['store_id', 'scope', 'scope_key', 'name_ar', 'name_en', 'is_active']);
        });
        Schema::table('units', function (Blueprint $table): void {
            $table->dropForeign('units_store_fk');
            $table->dropUnique('units_scope_code_unique');
            $table->dropIndex('units_scope_store_active_idx');
            $table->dropColumn(['store_id', 'scope', 'scope_key', 'name_ar', 'name_en', 'is_active']);
        });

        if (! $brandDuplicates) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->unique('slug');
            });
        }
        if (! $unitDuplicates) {
            Schema::table('units', function (Blueprint $table): void {
                $table->unique('code');
            });
        }
    }

    private function installPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $now = now();
        DB::table('permissions')->upsert([
            [
                'code' => 'lookups.view',
                'name' => 'View business lookup and master data',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'lookups.manage',
                'name' => 'Manage business lookup and master data',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['name', 'updated_at']);

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['lookups.view', 'lookups.manage'])
            ->pluck('id', 'code');
        $roleIds = DB::table('roles')
            ->whereIn('code', ['SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN', 'INVENTORY'])
            ->pluck('id', 'code');

        foreach (['SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN'] as $roleCode) {
            $roleId = $roleIds->get($roleCode);
            if ($roleId === null) {
                continue;
            }

            foreach (['lookups.view', 'lookups.manage'] as $permissionCode) {
                $permissionId = $permissionIds->get($permissionCode);
                if ($permissionId !== null) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        $inventoryRoleId = $roleIds->get('INVENTORY');
        $viewPermissionId = $permissionIds->get('lookups.view');
        if ($inventoryRoleId !== null && $viewPermissionId !== null) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $viewPermissionId,
                'role_id' => $inventoryRoleId,
            ]);
        }
    }
};
