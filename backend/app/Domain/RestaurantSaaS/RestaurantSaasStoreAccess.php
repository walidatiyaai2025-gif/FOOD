<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\TenantContextResolver;
use Illuminate\Http\Request;

final class RestaurantSaasStoreAccess
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly TenantContextResolver $tenants,
        private readonly SmartOrderingEnterpriseEntitlementBridge $entitlements,
    ) {}

    /** @return list<int> */
    public function allowedStoreIds(
        User $actor,
        string $permission,
        Request $request,
    ): array {
        if ($actor->hasRole('SUPER_ADMIN')) {
            $storeId = $request->integer('store_id');
            abort_unless($request->boolean('support_access') && $storeId > 0, 403);

            $this->tenants->retail($actor, $storeId, true, $request);
            abort_unless($actor->hasPermission($permission, $storeId), 403);
            $this->entitlements->assertStoreEnabled($storeId);

            return [$storeId];
        }

        $storeIds = $this->scope->allowedStoreIds($actor, $permission, 'b2c');
        $storeIds = $this->entitlements->enabledStoreIds($storeIds);
        abort_if($storeIds === [], 403);

        return $storeIds;
    }

    public function assertStore(
        User $actor,
        int $storeId,
        string $permission,
        ?string $expectedChannel = 'b2c',
    ): string {
        /** @var Request $request */
        $request = request();

        if ($actor->hasRole('SUPER_ADMIN')) {
            abort_unless($expectedChannel === null || strtolower($expectedChannel) === 'b2c', 404);
            $this->tenants->retail(
                $actor,
                $storeId,
                $request->boolean('support_access'),
                $request,
            );
            abort_unless($actor->hasPermission($permission, $storeId), 403);
        } else {
            $this->scope->assertStore($actor, $storeId, $permission, $expectedChannel ?? 'b2c');
        }

        $this->entitlements->assertStoreEnabled($storeId);

        return 'b2c';
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    public function filterEnabled(array $storeIds): array
    {
        return $this->entitlements->enabledStoreIds($storeIds);
    }
}
