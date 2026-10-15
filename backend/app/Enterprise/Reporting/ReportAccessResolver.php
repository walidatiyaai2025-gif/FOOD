<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;

final class ReportAccessResolver
{
    public function __construct(private readonly ModuleEntitlementResolver $entitlements) {}

    public function allowsView(User $actor, ReportDefinition $report, EntitlementScope $scope): bool
    {
        return $this->allows($actor, $report, $scope, false);
    }

    public function allowsExport(User $actor, ReportDefinition $report, EntitlementScope $scope): bool
    {
        return $this->allows($actor, $report, $scope, true);
    }

    private function allows(
        User $actor,
        ReportDefinition $report,
        EntitlementScope $scope,
        bool $export,
    ): bool {
        if (! $actor->is_active) {
            return false;
        }

        $storeId = $scope->type === EntitlementScope::STORE ? $scope->id : null;
        $permission = $export ? $report->exportPermission : $report->viewPermission;
        if (! $actor->hasPermission($permission, $storeId)) {
            return false;
        }

        return $this->entitlements->allows(
            $report->moduleCode,
            $scope,
            $export ? $report->exportCapability : $report->readCapability,
        );
    }
}
