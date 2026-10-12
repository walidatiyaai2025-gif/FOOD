<?php

namespace App\Services;

use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/** Adapter to the single official catalog owned by Customer VNext lane #1330. */
class LaunchCatalog
{
    private const SEEDER = 'Database\\Seeders\\FoodexLaunchSeeder';

    public function preflight(): array
    {
        if (! class_exists(self::SEEDER)) {
            return ['ready' => false, 'fingerprint' => null];
        }
        $callback = [app(self::SEEDER), 'preflight'];
        if (! is_callable($callback)) {
            return ['ready' => false, 'fingerprint' => null];
        }
        $result = $callback();
        $fingerprint = $result['fingerprint'] ?? null;
        $hasSeedContract = is_string($fingerprint) && trim($fingerprint) !== '';

        return [
            // #1330 deliberately makes production launch readiness stricter than
            // non-production seedability. QA/test reset may seed a deterministic
            // fixture while unresolved production-only image/source review remains.
            'ready' => app()->environment('production')
                ? ($result['ready'] ?? false) === true && $hasSeedContract
                : $hasSeedContract,
            'fingerprint' => $hasSeedContract ? $fingerprint : null,
        ];
    }

    public function reseed(): void
    {
        $preflight = $this->preflight();
        if (! $preflight['ready'] || ! is_string($preflight['fingerprint'])) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.catalog_blocked')]);
        }

        $seeder = app(self::SEEDER);
        if (! $seeder instanceof Seeder) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.catalog_blocked')]);
        }
        $seeder->__invoke();
    }
}
