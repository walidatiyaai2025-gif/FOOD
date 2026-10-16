<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;

final class SmartOrderingEnterpriseEntitlementBridge
{
    public const ENTERPRISE_MODULE = 'smart_ordering';

    public function __construct(
        private readonly ModuleEntitlementResolver $enterpriseEntitlements,
    ) {}

    public function allows(
        SmartOrderingSite $site,
        string|Module $module,
        bool $legacyEnabled,
    ): bool {
        if (! $legacyEnabled) {
            return false;
        }

        $moduleValue = $module instanceof Module ? $module->value : $module;
        if (! in_array($moduleValue, Module::values(), true)) {
            return false;
        }

        if (! (bool) config('smart_ordering.enterprise_entitlement_bridge', false)) {
            return true;
        }

        return $this->enterpriseEntitlements->allows(
            self::ENTERPRISE_MODULE,
            new EntitlementScope(EntitlementScope::STORE, (int) $site->store_id),
            $moduleValue,
        );
    }
}
