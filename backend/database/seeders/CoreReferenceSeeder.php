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
        ['code' => 'B2C_STORE_ADMIN', 'name' => 'Retail Store Admin', 'scope' => 'store'],
        ['code' => 'OPERATIONS', 'name' => 'B2B Operations', 'scope' => 'global'],
        ['code' => 'INVENTORY', 'name' => 'B2B Inventory', 'scope' => 'global'],
        ['code' => 'FINANCE', 'name' => 'B2B Finance', 'scope' => 'global'],
        ['code' => 'CUSTOMER_SUPPORT', 'name' => 'B2B Customer Support', 'scope' => 'global'],
        ['code' => 'RETAIL_OPERATIONS', 'name' => 'Retail Operations', 'scope' => 'store'],
        ['code' => 'RETAIL_INVENTORY', 'name' => 'Retail Inventory', 'scope' => 'store'],
        ['code' => 'RETAIL_FINANCE', 'name' => 'Retail Finance', 'scope' => 'store'],
        ['code' => 'RETAIL_CUSTOMER_SUPPORT', 'name' => 'Retail Customer Support', 'scope' => 'store'],
        ['code' => 'B2C_DRIVER', 'name' => 'Retail Driver', 'scope' => 'global'],
        ['code' => 'B2B_DRIVER', 'name' => 'B2B Driver', 'scope' => 'global'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedNamedReferences('store_types', self::STORE_TYPES);
            $this->seedRoles();
            $this->seedPermissions();
            $this->seedRolePermissions();
            $this->seedVanReleaseFeatureFlags();
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

    private function seedVanReleaseFeatureFlags(): void
    {
        foreach ([
            'commercial_rules_enabled',
            'flash_offers_enabled',
            'customer_flash_popup_enabled',
            'van_offers_enabled',
        ] as $key) {
            if (DB::table('settings')->whereNull('store_id')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'store_id' => null,
                'key' => $key,
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
