<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

final class V2UpdaterMemberBudgetConfigTest extends TestCase
{
    public function test_laravel_exposes_the_positive_production_zip_member_budget(): void
    {
        $canonical = require config_path('foodex.php');

        $this->assertArrayHasKey('update_max_zip_members', $canonical);
        $this->assertIsInt($canonical['update_max_zip_members']);
        $this->assertGreaterThanOrEqual(1, $canonical['update_max_zip_members']);
        $this->assertSame(
            max(1, (int) env('FOODEX_UPDATE_MAX_ZIP_MEMBERS', 50000)),
            $canonical['update_max_zip_members'],
        );
        $this->assertSame(
            $canonical['update_max_zip_members'],
            config('foodex.update_max_zip_members'),
        );
    }

    public function test_environment_override_and_non_positive_values_use_the_same_limit(): void
    {
        $environment = Env::getRepository();
        $key = 'FOODEX_UPDATE_MAX_ZIP_MEMBERS';
        $original = $environment->get($key);

        try {
            $environment->set($key, '7');
            $this->assertSame(7, (require config_path('foodex.php'))['update_max_zip_members']);

            $environment->set($key, '0');
            $this->assertSame(1, (require config_path('foodex.php'))['update_max_zip_members']);

            $environment->set($key, '-4');
            $this->assertSame(1, (require config_path('foodex.php'))['update_max_zip_members']);
        } finally {
            if ($original === null) {
                $environment->clear($key);
            } else {
                $environment->set($key, $original);
            }
        }
    }
}
