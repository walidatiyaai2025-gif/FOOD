<?php

namespace App\Enterprise\Modules;

use Throwable;

/**
 * BI HTTP entry points must enforce the same certified, healthy module state
 * used by the canonical Dashboard navigation; a hidden Sidebar is not a guard.
 */
final class EnterpriseBiReadinessGate
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleOperationalReadiness $readiness,
    ) {}

    public function assertReady(): void
    {
        try {
            $ready = $this->readiness->status(
                'enterprise_bi',
                $this->registry->manifest('enterprise_bi')->version,
                $this->registry->state('enterprise_bi'),
            ) === 'ready';
        } catch (Throwable) {
            $ready = false;
        }

        abort_unless($ready, 403);
    }
}
