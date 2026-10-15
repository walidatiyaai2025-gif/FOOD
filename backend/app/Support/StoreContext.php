<?php

namespace App\Support;

use RuntimeException;

final class StoreContext
{
    private ?ResolvedTenantContext $current = null;

    public function set(ResolvedTenantContext $context): void
    {
        $this->current = $context;
    }

    public function clear(): void
    {
        $this->current = null;
    }

    public function current(): ?ResolvedTenantContext
    {
        return $this->current;
    }

    public function require(): ResolvedTenantContext
    {
        if ($this->current === null) {
            throw new RuntimeException('Tenant/store context has not been resolved for this request.');
        }

        return $this->current;
    }

    public function storeId(): int
    {
        return $this->require()->requiresStore();
    }

    public function enterpriseScope(): EnterpriseDataScope
    {
        $scope = $this->require()->enterpriseScope;
        if ($scope === null) {
            throw new RuntimeException('Enterprise data scope has not been resolved for this context.');
        }

        return $scope;
    }
}
