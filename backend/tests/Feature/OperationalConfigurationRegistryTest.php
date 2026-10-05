<?php

namespace Tests\Feature;

use App\Models\OperationalConfiguration;
use App\Models\User;
use App\Services\OperationalConfigurationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalConfigurationRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_routing_mode_overrides_platform_without_affecting_other_territories(): void
    {
        $actor = $this->actor();
        $registry = app(OperationalConfigurationRegistry::class);

        $platform = $registry->createDraft(
            'routing.mode',
            ['mode' => 'automatic'],
            'enum',
            'platform',
            'global',
            $actor,
            'Platform routing default',
            failureMode: 'degrade',
        );
        $registry->publish($platform, $actor);

        $territory = $registry->createDraft(
            'routing.mode',
            ['mode' => 'manual'],
            'enum',
            'territory',
            'territory-a',
            $actor,
            'Dispatcher override',
            failureMode: 'degrade',
        );
        $registry->publish($territory, $actor);

        $this->assertSame('manual', $registry->routingMode([
            ['type' => 'territory', 'key' => 'territory-a'],
            ['type' => 'platform', 'key' => 'global'],
        ]));

        $this->assertSame('automatic', $registry->routingMode([
            ['type' => 'territory', 'key' => 'territory-b'],
            ['type' => 'platform', 'key' => 'global'],
        ]));
    }

    public function test_disabling_tracking_does_not_disable_field_order_capture(): void
    {
        $actor = $this->actor();
        $registry = app(OperationalConfigurationRegistry::class);
        $scope = [['type' => 'platform', 'key' => 'global']];

        $tracking = $registry->createDraft(
            'module.live_van_tracking',
            ['enabled' => false],
            'boolean',
            'platform',
            'global',
            $actor,
            'Tracking incident isolation',
            dependencies: ['van_access'],
        );
        $registry->publish($tracking, $actor);

        $orders = $registry->createDraft(
            'module.field_order_capture',
            ['enabled' => true],
            'boolean',
            'platform',
            'global',
            $actor,
            'Keep commerce operational',
            dependencies: ['van_access'],
        );
        $registry->publish($orders, $actor);

        $this->assertFalse($registry->moduleEnabled('live_van_tracking', $scope));
        $this->assertTrue($registry->moduleEnabled('field_order_capture', $scope));
    }

    public function test_publish_retires_previous_revision_and_keeps_audit_history(): void
    {
        $actor = $this->actor();
        $registry = app(OperationalConfigurationRegistry::class);

        $first = $registry->createDraft(
            'routing.mode',
            ['mode' => 'automatic'],
            'enum',
            'platform',
            'global',
            $actor,
        );
        $registry->publish($first, $actor);

        $second = $registry->createDraft(
            'routing.mode',
            ['mode' => 'hybrid'],
            'enum',
            'platform',
            'global',
            $actor,
        );
        $registry->publish($second, $actor);

        $this->assertSame('retired', $first->fresh()->status);
        $this->assertSame('published', $second->fresh()->status);
        $this->assertDatabaseCount('operational_configuration_audits', 4);
        $this->assertSame(2, OperationalConfiguration::query()->count());
    }

    public function test_invalid_routing_mode_fails_before_persistence(): void
    {
        $this->expectException(ValidationException::class);

        app(OperationalConfigurationRegistry::class)->createDraft(
            'routing.mode',
            ['mode' => 'magic'],
            'enum',
            'platform',
            'global',
            $this->actor(),
        );
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Operations Config Admin',
            'email' => 'ops-config-admin@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }
}
