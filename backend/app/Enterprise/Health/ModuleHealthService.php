<?php

namespace App\Enterprise\Health;

use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Carbon\CarbonImmutable;
use Throwable;

final class ModuleHealthService
{
    /** @var array<string, ModuleHealthReport> */
    private array $memo = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleManifestCatalog $manifests,
        private readonly ModuleHealthProbeRegistry $probes,
    ) {}

    public function report(string $moduleCode): ModuleHealthReport
    {
        if (isset($this->memo[$moduleCode])) {
            return $this->memo[$moduleCode];
        }

        try {
            $state = $this->registry->state($moduleCode);
        } catch (Throwable) {
            return $this->memo[$moduleCode] = ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::ERROR,
                'Module lifecycle state is invalid.',
            );
        }

        $stateReport = $this->reportForInactiveState($moduleCode, $state);
        if ($stateReport !== null) {
            return $this->memo[$moduleCode] = $stateReport;
        }

        return $this->memo[$moduleCode] = $this->runEnabledProbes($moduleCode);
    }

    /**
     * One failed module/probe is converted into an ERROR report and never aborts
     * health aggregation for unrelated modules.
     *
     * @return array<string, ModuleHealthReport>
     */
    public function all(): array
    {
        $reports = [];

        foreach ($this->manifests->all()->keys() as $moduleCode) {
            try {
                $reports[$moduleCode] = $this->report($moduleCode);
            } catch (Throwable) {
                $reports[$moduleCode] = ModuleHealthReport::state(
                    $moduleCode,
                    ModuleHealthStatus::ERROR,
                    'Module health evaluation failed.',
                );
            }
        }

        return $reports;
    }

    private function reportForInactiveState(
        string $moduleCode,
        ModuleState $state,
    ): ?ModuleHealthReport {
        return match ($state) {
            ModuleState::NOT_INSTALLED => ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::NOT_INSTALLED,
            ),
            ModuleState::INSTALLED, ModuleState::DISABLED => ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::DISABLED,
            ),
            ModuleState::MAINTENANCE => ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::MAINTENANCE,
            ),
            ModuleState::ERROR => ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::ERROR,
                'Module lifecycle is in ERROR state.',
            ),
            ModuleState::ENABLING, ModuleState::SUSPENDED => ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::DEGRADED,
                'Module is not currently available for normal operation.',
            ),
            ModuleState::ENABLED => null,
        };
    }

    private function runEnabledProbes(string $moduleCode): ModuleHealthReport
    {
        try {
            $probes = $this->probes->forModule($moduleCode);
        } catch (Throwable) {
            return ModuleHealthReport::state(
                $moduleCode,
                ModuleHealthStatus::ERROR,
                'Module health probe configuration is invalid.',
            );
        }

        if ($probes === []) {
            return ModuleHealthReport::state($moduleCode, ModuleHealthStatus::HEALTHY);
        }

        $checks = [];
        foreach ($probes as $probe) {
            try {
                $checks[] = $probe->check();
            } catch (Throwable $exception) {
                $checks[] = ModuleHealthCheck::error(
                    key: $probe::class,
                    metadata: ['exception_class' => $exception::class],
                );
            }
        }

        $now = CarbonImmutable::now('UTC');
        $hasError = false;
        $hasDegraded = false;

        foreach ($checks as $check) {
            $hasError = $hasError || $check->status === ModuleHealthStatus::ERROR;
            $hasDegraded = $hasDegraded
                || $check->status === ModuleHealthStatus::DEGRADED
                || $check->isStale($now);
        }

        $status = $hasError
            ? ModuleHealthStatus::ERROR
            : ($hasDegraded ? ModuleHealthStatus::DEGRADED : ModuleHealthStatus::HEALTHY);

        return new ModuleHealthReport(
            moduleCode: $moduleCode,
            status: $status,
            checkedAt: $now,
            checks: $checks,
        );
    }
}
