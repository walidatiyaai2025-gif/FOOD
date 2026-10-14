<?php

namespace Tests\Deployment;

use App\Domain\WhatsApp\Lifecycle;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WhatsAppGaProductionAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (getenv('FOODEX_DISPOSABLE_SERVICES') === 'true') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    public function test_real_mysql_redis_candidate_is_certified_but_remains_fail_closed_without_provider_credentials(): void
    {
        $policy = app(RuntimePolicy::class);
        $this->assertSame(config('whatsapp_certification.reference'), $policy->evidenceReference());
        $this->assertSame(Lifecycle::DISABLED, $policy->state()->lifecycle);
        $this->assertTrue($policy->state()->emergencyStop);
        $this->assertFalse($policy->isOperational());
        $this->assertFalse($policy->allowsInbound());
        $this->assertFalse($policy->allowsOutbound('+96555555555'));
        Http::assertNothingSent();

        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->assertSame(0, Artisan::call('foodex:certify-whatsapp-ga', [
            'user' => $actor->id,
            'revision' => 0,
        ]), Artisan::output());

        $state = app(RuntimePolicy::class)->state();
        $this->assertSame(Lifecycle::GA_READY, $state->lifecycle);
        $this->assertTrue($state->emergencyStop);
        $this->assertFalse(app(RuntimePolicy::class)->isOperational());
        Http::assertNothingSent();
    }
}
