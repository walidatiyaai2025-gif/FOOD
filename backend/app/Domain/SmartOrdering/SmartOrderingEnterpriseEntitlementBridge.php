<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseModuleEntitlement;

final class SmartOrderingEnterpriseEntitlementBridge
{
    public const ENTERPRISE_MODULE = 'smart_ordering';

    public function __construct(
        private readonly ModuleEntitlementResolver $enterpriseEntitlements,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * The STORE entitlement is the Restaurant SaaS master switch.
     *
     * Parent organization entitlements and legacy site/module entitlements may
     * narrow capabilities, but they can never substitute for an explicit store
     * grant. This keeps Restaurant SaaS opt-in per Retail Store.
     */
    public function storeEnabled(int $storeId): bool
    {
        if ($storeId < 1 || ! $this->modules->isEnabled(self::ENTERPRISE_MODULE)) {
            return false;
        }

        return EnterpriseModuleEntitlement::query()
            ->where('module_code', self::ENTERPRISE_MODULE)
            ->where('scope_type', EntitlementScope::STORE)
            ->where('scope_id', $storeId)
            ->where('is_enabled', true)
            ->exists();
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    public function enabledStoreIds(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $storeIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($storeIds === [] || ! $this->modules->isEnabled(self::ENTERPRISE_MODULE)) {
            return [];
        }

        return EnterpriseModuleEntitlement::query()
            ->where('module_code', self::ENTERPRISE_MODULE)
            ->where('scope_type', EntitlementScope::STORE)
            ->whereIn('scope_id', $storeIds)
            ->where('is_enabled', true)
            ->pluck('scope_id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function assertStoreEnabled(int $storeId): void
    {
        abort_unless($this->storeEnabled($storeId), 404);
    }

    public function allows(
        SmartOrderingSite $site,
        string|Module $module,
        bool $legacyEnabled,
    ): bool {
        if (! $legacyEnabled || ! $this->storeEnabled((int) $site->store_id)) {
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
