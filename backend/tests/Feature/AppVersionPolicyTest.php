<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppVersionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_policy_states_are_deterministic(): void
    {
        AppVersion::query()->create([
            'app' => 'customer', 'platform' => 'android',
            'latest_version' => '2.0.0', 'minimum_supported_version' => '1.5.0',
            'force_update' => false, 'store_url' => 'https://play.google.com/store/apps/details?id=example',
        ]);

        $this->getJson('/api/v1/app-version?platform=android&app=customer&current_version=1.0.0')
            ->assertOk()->assertJson(['status' => 'unsupported', 'force_update' => true, 'update_required' => true]);

        $this->getJson('/api/v1/app-version?platform=android&app=customer&current_version=1.8.0')
            ->assertOk()->assertJson(['status' => 'optional', 'force_update' => false, 'update_required' => true]);

        $this->getJson('/api/v1/app-version?platform=android&app=customer&current_version=2.0.0')
            ->assertOk()->assertJson(['status' => 'current', 'force_update' => false, 'update_required' => false]);
    }

    public function test_force_update_and_validation_are_backend_owned(): void
    {
        AppVersion::query()->create([
            'app' => 'driver', 'platform' => 'ios',
            'latest_version' => '3.0.0', 'minimum_supported_version' => '2.0.0',
            'force_update' => true, 'store_url' => 'https://apps.apple.com/app/id123456789',
        ]);

        $this->getJson('/api/v1/app-version?platform=ios&app=driver&current_version=2.5.0')
            ->assertOk()->assertJson(['status' => 'forced', 'force_update' => true]);

        $this->getJson('/api/v1/app-version?platform=windows&app=driver&current_version=2.5.0')->assertUnprocessable();
        $this->getJson('/api/v1/app-version?platform=ios&app=driver&current_version=banana')->assertUnprocessable();
    }

    public function test_super_admin_can_manage_policy_and_change_is_audited(): void
    {
        $user = User::query()->create(['name' => 'Super', 'email' => 'version@example.test', 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($user)->post('/admin/settings/app-versions', [
            'app' => 'customer', 'platform' => 'ios',
            'latest_version' => '2.1.0', 'minimum_supported_version' => '2.0.0',
            'store_url' => 'https://apps.apple.com/app/id123456789',
        ])->assertRedirect('/admin/settings/app-versions');

        $this->assertDatabaseHas('app_versions', ['app' => 'customer', 'platform' => 'ios', 'latest_version' => '2.1.0']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'app_version_policy.updated', 'user_id' => $user->id]);
    }

    public function test_non_platform_admin_cannot_manage_policy(): void
    {
        $user = User::query()->create(['name' => 'Ops', 'email' => 'ops-version@example.test', 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());

        $this->actingAs($user)->get('/admin/settings/app-versions')->assertForbidden();
    }
}
