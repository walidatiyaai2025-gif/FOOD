<?php

namespace Tests\Unit;

use App\Services\LegacyFinanceAssetMaterializerService;
use ReflectionMethod;
use Tests\TestCase;

final class LegacyFinanceAssetMaterializerServiceTest extends TestCase
{
    public function test_asset_age_matching_twenty_five_percent_rate_resolves_to_four_years(): void
    {
        $service = new LegacyFinanceAssetMaterializerService();

        $this->assertSame(48, $this->call($service, 'lifeMonths', ['4.000', '25.000']));
        $this->assertSame(48, $this->call($service, 'lifeMonths', ['48.000', '25.000']));
        $this->assertNull($this->call($service, 'lifeMonths', ['5.000', '25.000']));
    }

    public function test_expired_value_is_accepted_only_as_a_boolean_flag(): void
    {
        $service = new LegacyFinanceAssetMaterializerService();

        $this->assertTrue($this->call($service, 'flag', ['1']));
        $this->assertFalse($this->call($service, 'flag', ['0']));
        $this->assertNull($this->call($service, 'flag', ['2']));
        $this->assertNull($this->call($service, 'flag', ['75.00']));
    }

    public function test_source_asset_date_requires_valid_yyyymmdd(): void
    {
        $service = new LegacyFinanceAssetMaterializerService();

        $this->assertSame('2019-08-04', $this->call($service, 'date', ['20190804']));
        $this->assertNull($this->call($service, 'date', ['20191304']));
        $this->assertNull($this->call($service, 'date', ['0']));
    }

    public function test_service_encodes_fail_closed_native_finance_rules(): void
    {
        $source = file_get_contents(app_path('Services/LegacyFinanceAssetMaterializerService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'fixedassets_setting_101_is_not_trusted_authority' => true", $source);
        $this->assertStringContainsString("'asset_age_candidate_is_non_authoritative' => true", $source);
        $this->assertStringContainsString("'acquisition_journals_must_not_be_fabricated' => true", $source);
        $this->assertStringContainsString("'expired_value_is_flag_not_money' => true", $source);
    }

    private function call(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
