<?php

namespace App\Enterprise\Scheduling;

use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Outbox\OutboxDispatcher;
use App\Models\EnterpriseModule;

final class EnterpriseSchedulerAdapter
{
    public const MAX_BATCH = 100;

    public function __construct(private readonly OutboxDispatcher $outbox) {}

    public function dispatchDue(int $limit = 50): int
    {
        if (! $this->hasOperationalModules()) {
            return 0;
        }

        return $this->outbox->dispatchDue(
            max(1, min($limit, self::MAX_BATCH)),
        );
    }

    private function hasOperationalModules(): bool
    {
        return EnterpriseModule::query()
            ->where('state', ModuleState::ENABLED->value)
            ->exists();
    }
}
