<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProcurementSecondApprover
{
    /** @return array{User, ResolvedTenantContext} */
    public static function forSameStore(User $maker, ResolvedTenantContext $context): array
    {
        $storeId = $context->enterpriseScope?->storeId;
        if ($storeId === null) {
            throw new LogicException('Procurement checker fixture requires a store scope.');
        }

        $roleId = DB::table('user_store_roles')
            ->where('user_id', $maker->id)
            ->where('store_id', $storeId)
            ->value('role_id');
        if ($roleId === null) {
            throw new LogicException('Procurement checker fixture requires a scoped maker role.');
        }

        $checker = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $checker->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$checker, app(TenantContextResolver::class)->retail($checker, (int) $storeId)];
    }
}
