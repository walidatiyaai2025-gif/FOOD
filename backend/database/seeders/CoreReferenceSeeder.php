<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoreReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('store_types')->upsert([
            ['code' => 'B2B', 'name' => 'Wholesale', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'B2C', 'name' => 'Retail', 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'updated_at']);

        DB::table('roles')->upsert([
            ['code' => 'SUPER_ADMIN', 'name' => 'Platform Owner / Super Admin', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'B2B_ADMIN', 'name' => 'B2B Admin', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'B2C_STORE_ADMIN', 'name' => 'B2C Store Admin', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OPERATIONS', 'name' => 'Operations', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'INVENTORY', 'name' => 'Inventory', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FINANCE', 'name' => 'Finance', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CUSTOMER_SUPPORT', 'name' => 'Customer Support', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'B2C_DRIVER', 'name' => 'B2C Driver', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'B2B_DRIVER', 'name' => 'B2B Driver', 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'updated_at']);

        $abilities = (array) config('permissions.abilities', []);

        DB::table('permissions')->upsert(
            collect($abilities)
                ->map(fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all(),
            ['code'],
            ['name', 'updated_at'],
        );

        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        $matrix = (array) config('permissions.roles', []);

        foreach ($matrix as $roleCode => $permissionCodes) {
            $roleId = $roleIds->get($roleCode);

            if ($roleId === null) {
                continue;
            }

            $codes = in_array('*', $permissionCodes, true)
                ? array_keys($abilities)
                : $permissionCodes;

            $rows = collect($codes)
                ->map(function (string $permissionCode) use ($permissionIds, $roleId): ?array {
                    $permissionId = $permissionIds->get($permissionCode);

                    if ($permissionId === null) {
                        return null;
                    }

                    return [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('permission_role')->insertOrIgnore($rows);
            }
        }
    }
}
