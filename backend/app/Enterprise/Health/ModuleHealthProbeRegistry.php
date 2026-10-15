<?php

namespace App\Enterprise\Health;

use LogicException;

final class ModuleHealthProbeRegistry
{
    /**
     * Optional probe classes are resolved lazily and only for an ENABLED module.
     * Reading health for DISABLED/NOT_INSTALLED modules never resolves these classes.
     *
     * @return list<ModuleHealthProbe>
     */
    public function forModule(string $moduleCode): array
    {
        $definitions = config("enterprise_module_health.probes.{$moduleCode}", []);
        if (is_array($definitions) === false) {
            throw new LogicException("Health probe list for [{$moduleCode}] must be an array.");
        }

        $probes = [];
        foreach ($definitions as $class) {
            if (is_string($class) === false || $class === '') {
                throw new LogicException("Health probe definition for [{$moduleCode}] is invalid.");
            }

            $probe = app()->make($class);
            if ($probe instanceof ModuleHealthProbe === false) {
                throw new LogicException("Health probe [{$class}] must implement ModuleHealthProbe.");
            }

            $probes[] = $probe;
        }

        return $probes;
    }
}
