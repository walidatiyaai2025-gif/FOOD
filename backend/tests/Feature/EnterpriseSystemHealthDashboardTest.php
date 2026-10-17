<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnterpriseSystemHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_admin_sees_truthful_module_health_and_system_counts_in_both_locales(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [DashboardStaleProbe::class]);
        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);

        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'health-owner@example.test');

        EnterpriseOutboxMessage::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'health.test',
            'event_version' => 1,
            'payload' => json_encode(['kind' => 'health-test'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
        ]);
        EnterpriseWorkflowRequest::query()->create([
            'workflow_key' => 'health_test',
            'definition_version' => 1,
            'entity_type' => 'test',
            'entity_id' => '1',
            'scope_type' => 'platform',
            'scope_id' => null,
            'requester_id' => $admin->id,
            'status' => 'PENDING',
            'idempotency_key' => 'health-test-1',
            'context' => [],
        ]);

        $this->actingAs($admin)->get(route('admin.enterprise-system-health.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('System Administration')
            ->assertSee('data-foodex-module-overview', false)
            ->assertSee('foodex-module-kpis', false)
            ->assertSee(route('admin.inspector.index'), false)
            ->assertSee(route('admin.system-update.index'), false)
            ->assertSee(route('admin.production-dataset-switch.index'), false)
            ->assertSee('Sample Optional')
            ->assertSee('Degraded')
            ->assertSee('Stale checks')
            ->assertSee('Pending');

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin->fresh())->get(route('admin.enterprise-system-health.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('إدارة النظام');
    }

    public function test_non_platform_admin_is_denied_health_dashboard(): void
    {
        $finance = $this->userWithRole('FINANCE', 'en', 'health-finance@example.test');
        $this->actingAs($finance)->get(route('admin.enterprise-system-health.index'))->assertForbidden();
    }

    private function userWithRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}

final class DashboardStaleProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        $checkedAt = CarbonImmutable::now('UTC')->subMinutes(5);

        return ModuleHealthCheck::healthy(
            key: 'dashboard-stale',
            checkedAt: $checkedAt,
            staleAt: $checkedAt->addMinute(),
        );
    }
}
