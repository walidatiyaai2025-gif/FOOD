<?php

namespace App\Enterprise\Modules;

use App\Models\EnterpriseModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ModuleRegistry
{
    public function __construct(private readonly ModuleManifestCatalog $catalog) {}

    public function manifest(string $code): ModuleManifest
    {
        return $this->catalog->require($code);
    }

    public function registration(string $code): ?EnterpriseModule
    {
        return EnterpriseModule::query()->where('code', $code)->first();
    }

    public function requireRegistration(string $code): EnterpriseModule
    {
        return $this->registration($code)
            ?? throw (new ModelNotFoundException)->setModel(EnterpriseModule::class, [$code]);
    }

    public function state(string $code): ModuleState
    {
        return $this->registration($code)?->state ?? ModuleState::NOT_INSTALLED;
    }

    public function isEnabled(string $code): bool
    {
        return $this->state($code) === ModuleState::ENABLED;
    }

    public function syncManifest(string $code): EnterpriseModule
    {
        $manifest = $this->manifest($code);
        $registration = EnterpriseModule::query()->firstOrNew(['code' => $manifest->code]);

        if (! $registration->exists) {
            $registration->state = $manifest->defaultState;
            $registration->state_changed_at = now();
        }

        $registration->fill([
            'category' => $manifest->category,
            'manifest_version' => $manifest->version,
            'manifest' => $manifest->toArray(),
        ]);
        $registration->save();

        return $registration->refresh();
    }
}
