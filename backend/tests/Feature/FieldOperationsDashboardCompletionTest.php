<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ManagementReportService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldOperationsDashboardCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_field_operations_dashboard_exposes_scoped_analytics_and_exception_queue(): void
    {
        $admin = $this->superAdmin('field-dashboard@example.test');

        $this->actingAs($admin)
            ->get('/admin/field-operations/overview')
            ->assertOk()
            ->assertSee('data-field-operations-dashboard', false)
            ->assertSee('data-field-operations-kpis', false)
            ->assertSee('data-field-operations-analytics', false)
            ->assertSee('data-field-operations-exceptions', false)
            ->assertSee('data-foodex-module-kpi="late_deliveries"', false)
            ->assertSee('data-foodex-module-kpi="stale_location_signals"', false);
    }

    public function test_field_operations_report_is_exportable_and_enterprise_schedulable(): void
    {
        $catalog = app(ManagementReportService::class)->catalog();

        $this->assertSame('reports.families.field_operations', $catalog['field_operations'] ?? null);
        $this->assertSame(
            'field_operations',
            config('enterprise_reporting.reports.field_operations.delivery_performance.read_model'),
        );
        $this->assertSame(
            ['deliveries', 'completed', 'failed', 'on_time', 'late'],
            config('enterprise_reporting.reports.field_operations.delivery_performance.measures'),
        );
        $this->assertSame(
            'reports.export',
            config('enterprise_reporting.reports.field_operations.delivery_performance.export_permission'),
        );
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Field Operations Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
