<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoreReferenceSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string}>
     */
    private const STORE_TYPES = [
        ['code' => 'B2B', 'name' => 'Wholesale'],
        ['code' => 'B2C', 'name' => 'Retail'],
    ];

    /**
     * @var array<int, array{code: string, name: string}>
     */
    private const ROLES = [
        ['code' => 'SUPER_ADMIN', 'name' => 'Platform Owner / Super Admin'],
        ['code' => 'B2B_ADMIN', 'name' => 'B2B Admin'],
        ['code' => 'B2C_STORE_ADMIN', 'name' => 'B2C Store Admin'],
        ['code' => 'OPERATIONS', 'name' => 'Operations'],
        ['code' => 'INVENTORY', 'name' => 'Inventory'],
        ['code' => 'FINANCE', 'name' => 'Finance'],
        ['code' => 'CUSTOMER_SUPPORT', 'name' => 'Customer Support'],
        ['code' => 'B2C_DRIVER', 'name' => 'B2C Driver'],
        ['code' => 'B2B_DRIVER', 'name' => 'B2B Driver'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedNamedReferences('store_types', self::STORE_TYPES);
            $this->seedNamedReferences('roles', self::ROLES);
            $this->seedPermissions();
            $this->seedRolePermissions();
        });
    }

    /**
     * @param  array<int, array{code: string, name: string}>  $references
     */
    private function seedNamedReferences(string $table, array $references): void
    {
        $now = now();

        DB::table($table)->upsert(
            collect($references)
                ->map(fn (array $reference): array => [
                    ...$reference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
            ['code'],
            ['name'],
        );
    }

    private function seedPermissions(): void
    {
        $now = now();
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
