<?php

namespace App\Services;

use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Validation\ValidationException;

/** Adapter to the single official catalog owned by Customer VNext lane #1330. */
class LaunchCatalog
{
    public function preflight(): array
    {
        $result = app(FoodexLaunchSeeder::class)->preflight();
        $fingerprint = trim($result['fingerprint']);

        if ($fingerprint === '') {
            return ['ready' => false, 'fingerprint' => null];
        }

        return [
            // #1330 deliberately makes production launch readiness stricter than
            // non-production seedability. QA/test reset may seed a deterministic
            // fixture while unresolved production-only image/source review remains.
            'ready' => app()->environment('production') ? $result['ready'] : true,
            'fingerprint' => $fingerprint,
        ];
    }

    public function reseed(): void
    {
        $preflight = $this->preflight();
        if (! $preflight['ready'] || ! is_string($preflight['fingerprint'])) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.catalog_blocked')]);
        }

        app(FoodexLaunchSeeder::class)->__invoke();
    }
}
