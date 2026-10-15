<?php

namespace App\Services;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleManifest;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\EnterpriseModule;
use App\Models\EnterpriseModuleEntitlement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

final class EnterpriseModuleAdministration
{
    public function __construct(
        private readonly ModuleManifestCatalog $catalog,
        private readonly ModuleRegistry $registry,
        private readonly ModuleLifecycleManager $lifecycle,
    ) {}

    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function modules(): Collection
    {
        $manifests = $this->catalog->all();
        $codes = $manifests->keys()->values()->all();

        if ($codes === []) {
            return collect();
        }

        $registrations = EnterpriseModule::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        $entitlements = EnterpriseModuleEntitlement::query()
            ->whereIn('module_code', $codes)
            ->selectRaw('module_code, scope_type, is_enabled, COUNT(*) as aggregate')
            ->groupBy('module_code', 'scope_type', 'is_enabled')
            ->get()
            ->groupBy('module_code');

        return $manifests->map(
            fn (ModuleManifest $manifest): array => $this->snapshot(
                $manifest,
                $registrations->get($manifest->code),
                $entitlements->get($manifest->code, collect()),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function module(string $code): array
    {
        $manifest = $this->catalog->require($code);
        $registration = EnterpriseModule::query()->where('code', $code)->first();
        $entitlements = EnterpriseModuleEntitlement::query()
            ->where('module_code', $code)
            ->selectRaw('module_code, scope_type, is_enabled, COUNT(*) as aggregate')
            ->groupBy('module_code', 'scope_type', 'is_enabled')
            ->get();

        return $this->snapshot($manifest, $registration, $entitlements);
    }

    public function perform(string $code, string $action, ?Authenticatable $actor): EnterpriseModule
    {
        $this->catalog->require($code);

        $state = $this->registry->state($code);
        if ($state === ModuleState::NOT_INSTALLED) {
            $this->registry->syncManifest($code);
            $state = $this->registry->state($code);
        }

        return match ($action) {
            'enable' => $this->enable($code, $state, $actor),
            'disable' => $this->disable($code, $state, $actor),
            'suspend' => $this->move($code, $state, ModuleState::SUSPENDED, $actor),
            'maintenance' => $this->move($code, $state, ModuleState::MAINTENANCE, $actor),
            default => throw new LogicException('Unsupported module lifecycle action.'),
        };
    }

    private function enable(string $code, ModuleState $state, ?Authenticatable $actor): EnterpriseModule
    {
        if ($state === ModuleState::NOT_INSTALLED) {
            $this->lifecycle->transition($code, ModuleState::INSTALLED, $actor);
            $state = ModuleState::INSTALLED;
        }

        if ($state === ModuleState::INSTALLED) {
            $this->lifecycle->transition($code, ModuleState::DISABLED, $actor);
            $state = ModuleState::DISABLED;
        }

        if (in_array($state, [ModuleState::DISABLED, ModuleState::ERROR], true)) {
            $this->lifecycle->transition($code, ModuleState::ENABLING, $actor);
            $state = ModuleState::ENABLING;
        }

        if ($state === ModuleState::ENABLING) {
            return $this->lifecycle->transition($code, ModuleState::ENABLED, $actor);
        }

        if (in_array($state, [ModuleState::SUSPENDED, ModuleState::MAINTENANCE], true)) {
            return $this->lifecycle->transition($code, ModuleState::ENABLED, $actor);
        }

        if ($state === ModuleState::ENABLED) {
            return $this->registry->requireRegistration($code);
        }

        throw new LogicException('Module cannot be enabled from its current lifecycle state.');
    }

    private function disable(string $code, ModuleState $state, ?Authenticatable $actor): EnterpriseModule
    {
        if ($state === ModuleState::NOT_INSTALLED) {
            return $this->registry->syncManifest($code);
        }

        if ($state === ModuleState::INSTALLED) {
            return $this->lifecycle->transition($code, ModuleState::DISABLED, $actor);
        }

        if ($state === ModuleState::DISABLED) {
            return $this->registry->requireRegistration($code);
        }

        if (in_array($state, [
            ModuleState::ENABLING,
            ModuleState::ENABLED,
            ModuleState::SUSPENDED,
            ModuleState::MAINTENANCE,
            ModuleState::ERROR,
        ], true)) {
            return $this->lifecycle->transition($code, ModuleState::DISABLED, $actor);
        }

        throw new LogicException('Module cannot be disabled from its current lifecycle state.');
    }

    private function move(
        string $code,
        ModuleState $state,
        ModuleState $target,
        ?Authenticatable $actor,
    ): EnterpriseModule {
        if ($state === $target) {
            return $this->registry->requireRegistration($code);
        }

        if (! $state->canTransitionTo($target)) {
            throw new LogicException('Requested module lifecycle action is not allowed from the current state.');
        }

        return $this->lifecycle->transition($code, $target, $actor);
    }

    /**
     * @param  Collection<int, EnterpriseModuleEntitlement>  $entitlements
     * @return array<string, mixed>
     */
    private function snapshot(
        ModuleManifest $manifest,
        ?EnterpriseModule $registration,
        Collection $entitlements,
    ): array {
        $state = $registration?->state ?? ModuleState::NOT_INSTALLED;

        return [
            'code' => $manifest->code,
            'name' => Str::headline($manifest->code),
            'category' => Str::headline($manifest->category),
            'manifest_version' => $manifest->version,
            'state' => $state->value,
            'dependencies' => array_map(
                static fn (string $dependency): string => Str::headline($dependency),
                $manifest->hardDependencies,
            ),
            'optional_integrations' => array_map(
                static fn (string $integration): string => Str::headline($integration),
                $manifest->optionalIntegrations,
            ),
            'capability_count' => count($manifest->capabilities),
            'permission_count' => count($manifest->permissions),
            'entitlements' => $entitlements->map(static fn ($row): array => [
                'scope_type' => (string) $row->scope_type,
                'enabled' => (bool) $row->is_enabled,
                'count' => (int) $row->aggregate,
            ])->values()->all(),
            'actions' => $this->actionsFor($state),
        ];
    }

    /**
     * @return list<string>
     */
    private function actionsFor(ModuleState $state): array
    {
        return match ($state) {
            ModuleState::NOT_INSTALLED => ['enable'],
            ModuleState::INSTALLED => ['enable', 'disable'],
            ModuleState::DISABLED => ['enable'],
            ModuleState::ENABLING => ['enable', 'disable'],
            ModuleState::ENABLED => ['disable', 'suspend', 'maintenance'],
            ModuleState::SUSPENDED => ['enable', 'disable', 'maintenance'],
            ModuleState::MAINTENANCE => ['enable', 'disable', 'suspend'],
            ModuleState::ERROR => ['enable', 'disable', 'maintenance'],
        };
    }
}
