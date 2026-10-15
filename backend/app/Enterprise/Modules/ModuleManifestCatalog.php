<?php

namespace App\Enterprise\Modules;

use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ModuleManifestCatalog
{
    /**
     * Reading the catalog is configuration-only by design. It must never boot optional
     * module services, resolve provider credentials, or query the database.
     *
     * @return Collection<string, ModuleManifest>
     */
    public function all(): Collection
    {
        $definitions = (array) config('enterprise_modules.modules', []);

        return collect($definitions)
            ->map(static fn (array $definition, string $key): ModuleManifest => ModuleManifest::fromArray([
                ...$definition,
                'code' => $definition['code'] ?? $key,
            ]))
            ->keyBy(static fn (ModuleManifest $manifest): string => $manifest->code);
    }

    public function find(string $code): ?ModuleManifest
    {
        return $this->all()->get($code);
    }

    public function require(string $code): ModuleManifest
    {
        return $this->find($code)
            ?? throw new InvalidArgumentException("Unknown module manifest [{$code}].");
    }
}
