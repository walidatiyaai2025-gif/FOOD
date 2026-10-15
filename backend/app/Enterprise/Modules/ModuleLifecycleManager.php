<?php

namespace App\Enterprise\Modules;

use App\Models\EnterpriseModule;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ModuleLifecycleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleManifestCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function transition(
        string $code,
        ModuleState $target,
        ?Authenticatable $actor = null,
        ?string $error = null,
    ): EnterpriseModule {
        $this->registry->syncManifest($code);

        return DB::transaction(function () use ($code, $target, $actor, $error): EnterpriseModule {
            /** @var EnterpriseModule $module */
            $module = EnterpriseModule::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail();

            $current = $module->getAttribute('state');
            if (! $current instanceof ModuleState) {
                throw new LogicException("Module [{$code}] has an invalid lifecycle state.");
            }

            if ($current === $target) {
                return $module;
            }

            if (! $current->canTransitionTo($target)) {
                throw new LogicException(
                    "Illegal module lifecycle transition {$current->value} -> {$target->value} for [{$code}].",
                );
            }

            if ($target === ModuleState::ENABLING) {
                $this->assertHardDependenciesEnabled($code);
            }

            if (in_array($target, [ModuleState::DISABLED, ModuleState::NOT_INSTALLED], true)) {
                $this->assertNoActiveDependents($code);
            }

            $before = [
                'state' => $current->value,
                'last_error' => $module->last_error,
            ];

            $module->setAttribute('state', $target);
            $module->state_changed_at = now();
            $module->enabled_at = $target === ModuleState::ENABLED
                ? ($module->enabled_at ?? now())
                : $module->enabled_at;
            $module->last_error = $target === ModuleState::ERROR ? $error : null;
            $module->save();

            $this->audit->record(
                'enterprise.module.state_changed',
                $actor,
                $module,
                $before,
                [
                    'state' => $target->value,
                    'last_error' => $module->last_error,
                ],
            );

            return $module->refresh();
        });
    }

    private function assertHardDependenciesEnabled(string $code): void
    {
        $manifest = $this->catalog->require($code);
        if ($manifest->hardDependencies === []) {
            return;
        }

        $states = EnterpriseModule::query()
            ->whereIn('code', $manifest->hardDependencies)
            ->pluck('state', 'code');

        foreach ($manifest->hardDependencies as $dependency) {
            if (($states[$dependency] ?? null) !== ModuleState::ENABLED->value) {
                throw new LogicException(
                    "Module [{$code}] requires enabled hard dependency [{$dependency}].",
                );
            }
        }
    }

    private function assertNoActiveDependents(string $code): void
    {
        $dependentCodes = $this->catalog->all()
            ->filter(static fn (ModuleManifest $manifest): bool => in_array($code, $manifest->hardDependencies, true))
            ->keys()
            ->values()
            ->all();

        if ($dependentCodes === []) {
            return;
        }

        $active = EnterpriseModule::query()
            ->whereIn('code', $dependentCodes)
            ->whereIn('state', array_map(
                static fn (ModuleState $state): string => $state->value,
                [
                    ModuleState::ENABLING,
                    ModuleState::ENABLED,
                    ModuleState::SUSPENDED,
                    ModuleState::MAINTENANCE,
                ],
            ))
            ->value('code');

        if ($active !== null) {
            throw new LogicException(
                "Module [{$code}] cannot be disabled while dependent module [{$active}] is active.",
            );
        }
    }
}
