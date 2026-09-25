<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoreReferenceSeeder extends Seeder
{
    /** @var array<int, array{code:string,name:string}> */
    private const STORE_TYPES = [
        ['code' => 'B2B', 'name' => 'Wholesale'],
        ['code' => 'B2C', 'name' => 'Retail'],
    ];

    /** @var array<int, array{code:string,name:string,scope:string}> */
    private const ROLES = [
        ['code' => 'SUPER_ADMIN', 'name' => 'Platform Owner / Super Admin', 'scope' => 'global'],
        ['code' => 'B2B_ADMIN', 'name' => 'B2B Admin', 'scope' => 'global'],
        ['code' => 'B2C_STORE_ADMIN', 'name' => 'B2C Store Admin', 'scope' => 'store'],
        ['code' => 'OPERATIONS', 'name' => 'Operations', 'scope' => 'both'],
        ['code' => 'INVENTORY', 'name' => 'Inventory', 'scope' => 'both'],
        ['code' => 'FINANCE', 'name' => 'Finance', 'scope' => 'both'],
        ['code' => 'CUSTOMER_SUPPORT', 'name' => 'Customer Support', 'scope' => 'both'],
        ['code' => 'B2C_DRIVER', 'name' => 'B2C Driver', 'scope' => 'global'],
        ['code' => 'B2B_DRIVER', 'name' => 'B2B Driver', 'scope' => 'global'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedNamedReferences('store_types', self::STORE_TYPES);
            $this->seedRoles();
            $this->seedPermissions();
            $this->seedRolePermissions();
        });
    }

    /** @param array<int, array{code:string,name:string}> $references */
    private function seedNamedReferences(string $table, array $references): void
    {
        $now = now();

        DB::table($table)->upsert(
            collect($references)->map(fn (array $reference): array => [
                'code' => $reference['code'],
                'name' => $reference['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['code'],
            ['name'],
        );
    }

    private function seedRoles(): void
    {
        $now = now();

        DB::table('roles')->upsert(
            collect(self::ROLES)->map(fn (array $role): array => [
                ...$role,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['code'],
            ['name', 'scope', 'is_system'],
        );
    }

    private function seedPermissions(): void
    {
        $now = now();
        $abilities = (array) config('permissions.abilities', []);

        DB::table('permissions')->upsert(
            collect($abilities)->map(fn (string $name, string $code): array => [
                'code' => $code,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['code'],
            ['name'],
        );
    }

    private function seedRolePermissions(): void
    {
        $abilities = (array) config('permissions.abilities', []);
        $matrix = (array) config('permissions.roles', []);
        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');

        foreach ($matrix as $roleCode => $permissionCodes) {
            $roleId = $roleIds->get($roleCode);

            if ($roleId === null) {
                continue;
            }

            $codes = in_array('*', $permissionCodes, true) ? array_keys($abilities) : $permissionCodes;
            $rows = collect($codes)->map(function (string $permissionCode) use ($permissionIds, $roleId): ?array {
                $permissionId = $permissionIds->get($permissionCode);

                return $permissionId === null ? null : [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ];
            })->filter()->values()->all();

            DB::table('permission_role')->where('role_id', $roleId)->delete();

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }
}
