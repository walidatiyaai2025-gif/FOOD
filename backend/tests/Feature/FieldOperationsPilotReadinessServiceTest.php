<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FieldOperationConfigurationService;
use App\Services\FieldOperationsPilotReadinessService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FieldOperationsPilotReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_readiness_fails_closed_when_required_operational_dependencies_are_missing(): void
    {
        $result = app(FieldOperationsPilotReadinessService::class)->evaluate(['territory' => '999']);

        $this->assertFalse($result['ready']);
        $this->assertSame(FieldOperationsPilotReadinessService::STATE_NOT_READY, $result['state']);
        $this->assertContains('geometry', $result['failed_checks']);
        $this->assertContains('address_mapping', $result['failed_checks']);
        $this->assertContains('eligible_van_pool', $result['failed_checks']);
    }

    public function test_scope_specific_suspend_overrides_enabled_state_without_disabling_other_scopes(): void
    {
        $actor = User::factory()->create();
        $config = app(FieldOperationConfigurationService::class);

        $config->upsertDefinition('pilot.activation.enabled', 'boolean', false, 'fail_closed');
        $config->upsertDefinition('pilot.activation.suspended', 'boolean', false, 'fail_closed');

        $enabled = $config->createDraft($actor, 'pilot.activation.enabled', 'territory', '10', true);
        $config->publish($actor, $enabled);

        $suspended = $config->createDraft($actor, 'pilot.activation.suspended', 'territory', '10', true);
        $config->publish($actor, $suspended);

        $service = app(FieldOperationsPilotReadinessService::class);
        $territory10 = $service->evaluate(['territory' => '10']);
        $territory11 = $service->evaluate(['territory' => '11']);

        $this->assertTrue($territory10['activation']['enabled']);
        $this->assertTrue($territory10['activation']['suspended']);
        $this->assertFalse($territory11['activation']['enabled']);
        $this->assertFalse($territory11['activation']['suspended']);
    }

    public function test_activation_refuses_to_publish_when_readiness_is_not_green(): void
    {
        $actor = User::factory()->create();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(FieldOperationsPilotReadinessService::class)
            ->activate($actor, 'territory', '77', 'pilot start');
    }
}
