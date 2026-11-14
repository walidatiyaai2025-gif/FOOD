<?php

namespace Tests\Unit;

use App\Models\AppVersion;
use App\Services\AppVersionPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class V2AppVersionBuildMetadataTest extends TestCase
{
    public function test_equal_semantic_versions_with_distinct_build_metadata_never_force_an_update(): void
    {
        $policy = new AppVersion([
            'app' => 'customer',
            'platform' => 'android',
            'minimum_supported_version' => '2.0.0+minimum.12',
            'latest_version' => '2.0.0+latest.99',
            'force_update' => true,
        ]);

        $result = (new AppVersionPolicy)->evaluate($policy, 'v2.0.0+client.1');

        $this->assertSame('current', $result['status']);
        $this->assertFalse($result['update_required']);
        $this->assertFalse($result['force_update']);
        $this->assertSame('2.0.0+latest.99', $result['latest_version']);
        $this->assertSame('2.0.0+minimum.12', $result['minimum_supported_version']);
    }

    public function test_true_older_and_prerelease_versions_still_require_updates(): void
    {
        $policy = new AppVersion([
            'app' => 'driver',
            'platform' => 'ios',
            'minimum_supported_version' => '1.5.0+server.9',
            'latest_version' => '2.0.0+server.4',
            'force_update' => true,
        ]);
        $evaluator = new AppVersionPolicy;

        $this->assertSame('unsupported', $evaluator->evaluate($policy, '1.4.9+client.999')['status']);
        $this->assertSame('forced', $evaluator->evaluate($policy, '1.5.0+client.1')['status']);
        $this->assertSame('forced', $evaluator->evaluate($policy, '2.0.0-rc.1+client.50')['status']);
        $this->assertSame('current', $evaluator->evaluate($policy, '2.0.0+client.1')['status']);
    }

    public function test_policy_order_ignores_build_metadata_but_still_checks_real_precedence(): void
    {
        $evaluator = new AppVersionPolicy;
        $evaluator->assertPolicyOrder('v2.0.0+build.99', 'V2.0.0+build.1');
        $evaluator->assertPolicyOrder('2.0.0-rc.1+build.9', '2.0.0+build.1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimum supported version cannot exceed latest version.');
        $evaluator->assertPolicyOrder('2.1.0+build.1', '2.0.0+build.999');
    }

    public function test_invalid_build_metadata_syntax_stays_rejected(): void
    {
        $evaluator = new AppVersionPolicy;

        foreach (['2.0.0+', '2.0.0++bad', '2.0.0-rc.1+!', '2.0.0+bad space', 'banana'] as $invalid) {
            try {
                $evaluator->assertPolicyOrder($invalid, '2.1.0');
                $this->fail('Malformed version accepted: '.$invalid);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid application version.', $exception->getMessage());
            }
        }
    }
}
