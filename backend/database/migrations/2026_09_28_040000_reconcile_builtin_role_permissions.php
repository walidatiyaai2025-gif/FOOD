<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{name:string,scope:string}> */
    private const SYSTEM_ROLES = [
        'SUPER_ADMIN' => ['name' => 'Platform Owner / Super Admin', 'scope' => 'global'],
        'B2B_ADMIN' => ['name' => 'B2B Admin', 'scope' => 'global'],
        'B2C_STORE_ADMIN' => ['name' => 'Retail Store Admin', 'scope' => 'store'],
        'OPERATIONS' => ['name' => 'B2B Operations', 'scope' => 'global'],
        'INVENTORY' => ['name' => 'B2B Inventory', 'scope' => 'global'],
        'FINANCE' => ['name' => 'B2B Finance', 'scope' => 'global'],
        'CUSTOMER_SUPPORT' => ['name' => 'B2B Customer Support', 'scope' => 'global'],
        'RETAIL_OPERATIONS' => ['name' => 'Retail Operations', 'scope' => 'store'],
        'RETAIL_INVENTORY' => ['name' => 'Retail Inventory', 'scope' => 'store'],
        'RETAIL_FINANCE' => ['name' => 'Retail Finance', 'scope' => 'store'],
        'RETAIL_CUSTOMER_SUPPORT' => ['name' => 'Retail Customer Support', 'scope' => 'store'],
        'B2C_DRIVER' => ['name' => 'Retail Driver', 'scope' => 'global'],
        'B2B_DRIVER' => ['name' => 'B2B Driver', 'scope' => 'global'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $abilities = (array) config('permissions.abilities', []);
            $matrix = (array) config('permissions.roles', []);

            DB::table('permissions')->upsert(
                collect($abilities)->map(fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all(),
                ['code'],
                ['name', 'updated_at'],
            );

            foreach (self::SYSTEM_ROLES as $code => $definition) {
                DB::table('roles')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $definition['name'],
                        'scope' => $definition['scope'],
                        'is_system' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }

            $roleIds = DB::table('roles')
                ->whereIn('code', array_keys(self::SYSTEM_ROLES))
                ->pluck('id', 'code');
            $permissionIds = DB::table('permissions')->pluck('id', 'code');

            foreach (self::SYSTEM_ROLES as $roleCode => $_definition) {
                $roleId = $roleIds->get($roleCode);
                if ($roleId === null) {
                    continue;
                }

                $configured = array_values((array) ($matrix[$roleCode] ?? []));
                $codes = in_array('*', $configured, true)
                    ? array_keys($abilities)
                    : array_values(array_filter(
                        $configured,
                        static fn ($code): bool => is_string($code) && array_key_exists($code, $abilities),
                    ));

                $rows = collect($codes)
                    ->map(function (string $permissionCode) use ($permissionIds, $roleId): ?array {
                        $permissionId = $permissionIds->get($permissionCode);

                        return $permissionId === null ? null : [
                            'permission_id' => (int) $permissionId,
                            'role_id' => (int) $roleId,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                DB::table('permission_role')->where('role_id', $roleId)->delete();

                if ($rows !== []) {
                    DB::table('permission_role')->insert($rows);
                }
            }

            $globalRoleIds = DB::table('roles')
                ->whereIn('code', collect(self::SYSTEM_ROLES)
                    ->filter(fn (array $definition): bool => $definition['scope'] === 'global')
                    ->keys()
                    ->all())
                ->pluck('id');

            if ($globalRoleIds->isNotEmpty()) {
                DB::table('user_store_roles')->whereIn('role_id', $globalRoleIds)->delete();
            }
        });
    }

    public function down(): void
    {
        // This migration reconciles canonical built-in RBAC state. Reversing it would
        // reintroduce an unknown stale permission matrix, so rollback is intentionally
        // left non-destructive.
    }
};
