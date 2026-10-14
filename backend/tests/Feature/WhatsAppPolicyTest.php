<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\CertificationAuthority;
use App\Domain\WhatsApp\Lifecycle;
use App\Domain\WhatsApp\OutboundExecution;
use App\Domain\WhatsApp\OutboundPaused;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\StateManager;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Services\AuditLogger;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WhatsAppPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function configureRow(array $overrides = []): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill(array_replace([
            'lifecycle' => 'INTERNAL_TEST', 'emergency_stop' => false,
            'capabilities' => array_fill_keys(array_column(Capability::cases(), 'value'), true),
            'test_numbers' => ['+96555555555'],
        ], $overrides))->save();
    }

    public function test_default_install_is_dormant_without_credentials(): void
    {
        $policy = app(RuntimePolicy::class);
        $this->assertSame(Lifecycle::DISABLED, $policy->state()->lifecycle);
        $this->assertFalse($policy->isOperational());
        $this->assertFalse($policy->allowsOutbound('+96555555555'));
        $this->assertFalse($policy->allowsInbound());
        $this->assertFalse($policy->allowsAgentInbox());
        $this->assertNull($policy->evidenceReference());
        $this->get('/health')->assertOk();
        Http::assertNothingSent();
    }

    public function test_all_states_capabilities_and_emergency_stop_fail_closed_without_certification(): void
    {
        $policy = app(RuntimePolicy::class);
        foreach (Lifecycle::cases() as $lifecycle) {
            foreach ([false, true] as $stop) {
                $this->configureRow(['lifecycle' => $lifecycle->value, 'emergency_stop' => $stop]);
                foreach (Capability::cases() as $purpose) {
                    $expected = $lifecycle === Lifecycle::INTERNAL_TEST && ! $stop && $purpose->isOutbound();
                    $this->assertSame($expected, $policy->allowsOutbound('+96555555555', $purpose), $lifecycle->value.'/'.$purpose->value);
                    $this->assertFalse($policy->allowsOutbound('+96566666666', $purpose));
                }
            }
        }
        $this->configureRow(['capabilities' => ['campaigns' => true]]);
        $this->assertFalse($policy->allowsCampaigns('+96555555555'));
    }

    public function test_kill_switch_preserves_inbound_inbox_and_configuration_and_rechecks_queued_execution(): void
    {
        $this->configureRow();
        $policy = app(RuntimePolicy::class);
        $execution = app(OutboundExecution::class);
        $this->assertSame('sent', $execution->run('+96555555555', Capability::TRANSACTIONAL, fn () => 'sent'));
        app(StateManager::class)->setEmergencyStop($this->admin(), true, 0);
        $this->assertTrue($policy->isEmergencyStopActive());
        $this->assertTrue($policy->allowsInbound());
        $this->assertTrue($policy->allowsAgentInbox());
        $this->assertSame(['+96555555555'], $policy->state()->testNumbers);
        $called = false;
        try {
            $execution->run('+96555555555', Capability::CAMPAIGNS, function () use (&$called): void {
                $called = true;
            });
            $this->fail('Paused work executed.');
        } catch (OutboundPaused) {
            $this->assertFalse($called);
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.emergency_enabled']);
    }

    public function test_production_activation_is_impossible_even_with_forged_readiness(): void
    {
        $actor = $this->admin();
        foreach (Lifecycle::cases() as $state) {
            $this->configureRow(['lifecycle' => $state->value]);
            try {
                app(StateManager::class)->activate($actor, 0);
                $this->fail('Uncertified activation succeeded.');
            } catch (ValidationException) {
                $this->assertSame($state, app(RuntimePolicy::class)->state()->lifecycle);
            }
        }
        $this->assertSame(5, AuditLog::query()->where('event', 'whatsapp.activation_rejected')->count());
    }

    public function test_certification_contract_requires_readiness_actor_and_stop_clearance(): void
    {
        // A test-only authority exercises the future contract; runtime binds UncertifiedProgram.
        $authority = $this->mock(CertificationAuthority::class);
        $authority->shouldReceive('validEvidenceReference')->andReturn('test-only-certification');
        $actor = $this->admin();
        $this->configureRow(['lifecycle' => 'GA_READY', 'emergency_stop' => true]);
        $policy = app(RuntimePolicy::class);
        $this->assertFalse($policy->isOperational());
        $this->assertFalse($policy->permitsActivation($actor));
        app(StateManager::class)->setEmergencyStop($actor, false, 0);
        app(StateManager::class)->activate($actor, 1);
        $this->assertTrue($policy->allowsOutbound('+96566666666'));
        app(StateManager::class)->setEmergencyStop($actor, true, 2);
        $this->assertFalse($policy->allowsOutbound('+96566666666'));
        $this->assertTrue($policy->allowsInbound());
    }

    public function test_missing_schema_and_corrupt_optional_state_do_not_break_health(): void
    {
        foreach ([['lifecycle' => 'UNKNOWN'], ['capabilities' => ['outbound' => 'true']], ['test_numbers' => ['*']]] as $invalid) {
            $this->configureRow($invalid);
            $this->assertFalse(app(RuntimePolicy::class)->isOperational());
            $this->get('/health')->assertOk();
        }
        Schema::drop('whats_app_module_states');
        $this->assertFalse(app(RuntimePolicy::class)->isOperational());
        $this->get('/health')->assertOk();
    }

    public function test_allowlist_is_exact_and_all_sensitive_changes_are_audited(): void
    {
        $actor = $this->admin();
        $manager = app(StateManager::class);
        $manager->configure($actor, ['outbound' => true], ['+96555555555'], 0);
        $manager->transition($actor, Lifecycle::INTERNAL_TEST, 1);
        $manager->setEmergencyStop($actor, false, 2);
        foreach (['', '*', '96555555555', '+965 55555555', '+96555555555\n', '+96566666666'] as $invalid) {
            $this->assertFalse(app(RuntimePolicy::class)->allowsOutbound($invalid));
        }
        $this->assertTrue(app(RuntimePolicy::class)->allowsOutbound('+96555555555'));
        $this->assertSame(3, AuditLog::query()->where('event', 'like', 'whatsapp.%')->count());
        $audit = AuditLog::query()->where('event', 'whatsapp.lifecycle_changed')->firstOrFail();
        $this->assertSame('DISABLED', $audit->before['lifecycle']);
        $this->assertSame('INTERNAL_TEST', $audit->after['lifecycle']);
        $this->assertSame($actor->id, $audit->user_id);
    }

    public function test_stale_form_cannot_clear_emergency_stop(): void
    {
        $actor = $this->admin();
        app(StateManager::class)->transition($actor, Lifecycle::INTERNAL_TEST, 0);
        $this->expectException(ValidationException::class);
        app(StateManager::class)->setEmergencyStop($actor, false, 0);
    }

    public function test_audit_failure_rolls_back_state_change(): void
    {
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(StateManager::class)->transition($this->admin(), Lifecycle::INTERNAL_TEST, 0);
            $this->fail('Mutation should roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }
        $this->assertSame('DISABLED', DB::table('whats_app_module_states')->value('lifecycle'));
    }
}
