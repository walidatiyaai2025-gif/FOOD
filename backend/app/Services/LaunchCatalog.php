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

        return [
            'ready' => ($result['ready'] ?? false) === true,
            'fingerprint' => $result['fingerprint'] ?? null,
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
