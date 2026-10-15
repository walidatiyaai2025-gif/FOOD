<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\EnterpriseOutboxMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseSchedulerCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_reuses_existing_laravel_contract_with_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'foodex:dispatch-enterprise-outbox --limit=50',
            ));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_disabled_modules_make_enterprise_scheduler_a_noop(): void
    {
        app(ModuleRegistry::class)->syncManifest('sample_optional');

        $message = app(TransactionalOutbox::class)->enqueue(
            VersionedDomainEvent::create(
                type: 'EnterpriseModuleStateChanged',
                version: 1,
                scope: ['organization_id' => 10],
                payload: ['reference' => 'disabled'],
            ),
        );

        $this->artisan('foodex:dispatch-enterprise-outbox --limit=10')
            ->expectsOutput('Dispatched 0 Enterprise outbox message(s).')
            ->assertSuccessful();

        $this->assertSame('pending', $message->refresh()->status);
    }

    public function test_enabled_module_dispatch_is_bounded_to_requested_slice(): void
    {
        $module = app(ModuleRegistry::class)->syncManifest('sample_optional');
        $module->forceFill([
            'state' => ModuleState::ENABLED,
            'enabled_at' => now(),
            'state_changed_at' => now(),
        ])->save();

        foreach (range(1, 3) as $index) {
            app(TransactionalOutbox::class)->enqueue(
                VersionedDomainEvent::create(
                    type: 'EnterpriseModuleStateChanged',
                    version: 1,
                    scope: ['organization_id' => 20],
                    payload: ['sequence' => $index],
                ),
            );
        }

        $this->artisan('foodex:dispatch-enterprise-outbox --limit=2')
            ->expectsOutput('Dispatched 2 Enterprise outbox message(s).')
            ->assertSuccessful();

        $this->assertSame(2, EnterpriseOutboxMessage::query()->where('status', 'dispatched')->count());
        $this->assertSame(1, EnterpriseOutboxMessage::query()->where('status', 'pending')->count());
    }

    public function test_scheduler_clamps_requested_batch_to_cpanel_safe_maximum(): void
    {
        $module = app(ModuleRegistry::class)->syncManifest('sample_optional');
        $module->forceFill([
            'state' => ModuleState::ENABLED,
            'enabled_at' => now(),
            'state_changed_at' => now(),
        ])->save();

        foreach (range(1, 101) as $index) {
            app(TransactionalOutbox::class)->enqueue(
                VersionedDomainEvent::create(
                    type: 'EnterpriseModuleStateChanged',
                    version: 1,
                    scope: ['organization_id' => 30],
                    payload: ['sequence' => $index],
                ),
            );
        }

        $this->artisan('foodex:dispatch-enterprise-outbox --limit=1000')
            ->expectsOutput('Dispatched 100 Enterprise outbox message(s).')
            ->assertSuccessful();

        $this->assertSame(100, EnterpriseOutboxMessage::query()->where('status', 'dispatched')->count());
        $this->assertSame(1, EnterpriseOutboxMessage::query()->where('status', 'pending')->count());
    }
}
