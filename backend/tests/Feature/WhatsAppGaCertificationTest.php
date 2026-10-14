<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\CertificationAuthority;
use App\Domain\WhatsApp\Lifecycle;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\StateManager;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WhatsAppGaCertificationTest extends TestCase
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

    public function test_canonical_manifest_is_exactly_twenty_green_checks_with_zero_p0_p1_risk(): void
    {
        $manifest = config('whatsapp_certification');
        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame(1407, $manifest['program_issue']);
        $this->assertSame('PASS', $manifest['status']);
        $this->assertSame(0, $manifest['unresolved_p0_p1']);
        $this->assertCount(20, $manifest['checks']);
        $this->assertNotContains(false, array_values($manifest['checks']), true);

        $encoded = json_encode($manifest['checks'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertSame($manifest['checks_sha256'], hash('sha256', $encoded));
        $this->assertSame($manifest['reference'], app(CertificationAuthority::class)->validEvidenceReference());
    }

    public function test_certified_code_still_installs_disabled_emergency_stopped_and_without_credentials(): void
    {
        $policy = app(RuntimePolicy::class);

        $this->assertSame(Lifecycle::DISABLED, $policy->state()->lifecycle);
        $this->assertTrue($policy->state()->emergencyStop);
        $this->assertNotNull($policy->evidenceReference());
        $this->assertFalse($policy->isOperational());
        $this->assertFalse($policy->allowsInbound());
        $this->assertFalse($policy->allowsOutbound('+96555555555'));
        $this->get('/health')->assertOk();
        Http::assertNothingSent();
    }

    public function test_ga_ready_promotion_is_separate_from_activation_and_reengages_emergency_stop(): void
    {
        $actor = $this->admin();
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => Lifecycle::INTERNAL_TEST->value,
            'emergency_stop' => false,
            'capabilities' => array_fill_keys(array_column(Capability::cases(), 'value'), true),
            'test_numbers' => ['+96555555555'],
        ])->save();

        $manager = app(StateManager::class);
        $manager->certifyGaReady($actor, 0);

        $state = app(RuntimePolicy::class)->state();
        $this->assertSame(Lifecycle::GA_READY, $state->lifecycle);
        $this->assertTrue($state->emergencyStop);
        $this->assertFalse(app(RuntimePolicy::class)->isOperational());
        $this->assertFalse(app(RuntimePolicy::class)->permitsActivation($actor));

        $audit = AuditLog::query()->where('event', 'whatsapp.ga_certified')->firstOrFail();
        $this->assertSame(config('whatsapp_certification.reference'), $audit->after['certification_evidence']);
        $this->assertSame('GA_READY', $audit->after['lifecycle']);

        $manager->setEmergencyStop($actor, false, 1);
        $this->assertTrue(app(RuntimePolicy::class)->permitsActivation($actor));
        $manager->activate($actor, 2);
        $this->assertSame(Lifecycle::ENABLED, app(RuntimePolicy::class)->state()->lifecycle);
    }

    public function test_ga_ready_promotion_fails_closed_when_certificate_is_missing_or_invalid(): void
    {
        $this->mock(CertificationAuthority::class)
            ->shouldReceive('validEvidenceReference')
            ->andReturn(null);

        $this->expectException(ValidationException::class);
        app(StateManager::class)->certifyGaReady($this->admin(), 0);
    }

    public function test_ordinary_lifecycle_transition_still_cannot_self_certify(): void
    {
        $this->expectException(ValidationException::class);
        app(StateManager::class)->transition($this->admin(), Lifecycle::GA_READY, 0);
    }

    public function test_one_shot_certification_command_requires_the_canonical_authority_and_super_admin(): void
    {
        $actor = $this->admin();

        $this->assertSame(0, Artisan::call('foodex:certify-whatsapp-ga', [
            'user' => $actor->id,
            'revision' => 0,
        ]), Artisan::output());

        $state = app(RuntimePolicy::class)->state();
        $this->assertSame(Lifecycle::GA_READY, $state->lifecycle);
        $this->assertTrue($state->emergencyStop);
        $this->assertStringContainsString(config('whatsapp_certification.reference'), Artisan::output());
    }
}
