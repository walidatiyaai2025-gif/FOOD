<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductionLaunchController;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductionLaunchPreflight;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionLaunchGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        Route::middleware('web')->get(
            '/__production-launch-test',
            [ProductionLaunchController::class, 'index'],
        );
        Route::middleware('web')->post(
            '/__production-launch-test',
            [ProductionLaunchController::class, 'store'],
        );
    }

    public function test_only_active_super_admin_can_open_launch_surface(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/__production-launch-test')
            ->assertOk()
            ->assertSee('data-foodex-production-launch="true"', false)
            ->assertSee('Production Launch Gate');

        $regular = User::query()->create([
            'name' => 'Regular Launch User',
            'email' => 'regular-launch@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($regular)
            ->get('/__production-launch-test')
            ->assertForbidden();
    }

    public function test_runtime_surface_is_fail_closed_when_required_evidence_is_missing(): void
    {
        $owner = $this->superAdmin();

        $response = $this->actingAs($owner)->get('/__production-launch-test');

        $response
            ->assertOk()
            ->assertSee('data-launch-blocked="true"', false)
            ->assertSee('Launch remains blocked.')
            ->assertSee('Exact-head Required CI')
            ->assertSee('Customer / Driver / Van builds');

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('launch');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_wrong_password_and_wrong_confirmation_never_create_approval(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
                'current_password' => 'wrong',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => 'LAUNCH',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_arabic_surface_is_rtl_and_system_copy_is_localized(): void
    {
        $owner = $this->superAdmin('ar');

        $this->actingAs($owner)
            ->get('/__production-launch-test')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('بوابة إطلاق الإنتاج')
            ->assertSee('فحوصات جاهزية الإنتاج')
            ->assertDontSee('Launch remains blocked.');
    }

    public function test_successful_owner_approval_audits_release_lineage(): void
    {
        $owner = $this->superAdmin();
        $service = app(ProductionLaunchPreflight::class);
        $checks = [];

        foreach (ProductionLaunchPreflight::REQUIRED_CHECKS as $code) {
            $checks[$code] = [
                'passed' => true,
                'detail' => 'passed',
                'source' => 'test-evidence',
            ];
        }

        $request = Request::create('/admin/settings/production-launch', 'POST');
        $request->setUserResolver(static fn (): User => $owner);

        $service->approve(
            $owner,
            $request,
            $service->decide($checks),
            ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
            app(AuditLogger::class),
        );

        $audit = AuditLog::query()
            ->where('event', 'foodex.production_launch.approved')
            ->firstOrFail();

        $this->assertSame(
            ProductionLaunchPreflight::REQUIRED_CHECKS,
            $audit->after['required_checks'],
        );
        $this->assertArrayHasKey('release', $audit->after);
        $this->assertArrayHasKey('version', $audit->after['release']);
        $this->assertArrayHasKey('source_commit', $audit->after['release']);
        $this->assertSame($owner->id, $audit->user_id);
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'FOODEX Launch Owner',
            'email' => 'launch-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
