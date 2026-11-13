<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Services\VanRuntimeContextResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2921 / historical DOCX Table 5, data row 16.
 *
 * The archived B2B_DRIVER role snapshot cannot attest a real person's current
 * identity, driver record, Van assignment or administrative entitlements.
 * All accounts and assignments in this test are synthetic.
 */
final class V2ExportedAccountRow16BoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_b2b_driver_role_without_a_van_assignment_denies_every_dashboard_and_delivery_runtime(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic V2 Table 5 Row 16',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $role = Role::query()->where('code', 'B2B_DRIVER')->firstOrFail();
        $user->roles()->attach($role);

        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->assertSame(0, $user->storeRoleAssignments()->count());
        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('finance.view'));
        $this->assertFalse($user->hasPermission('deliveries.b2c.execute'));
        $this->assertFalse($user->hasPermission('van.login'));

        $runtime = app(VanRuntimeContextResolver::class);
        $this->assertNull($runtime->resolve($user)['selected']);
        $this->assertFalse($runtime->canUseRuntime($user));

        foreach (['en', 'ar'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $actor = $user->fresh();

            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();

            // A legacy Wholesale Driver role is not a Retail Driver session.
            Sanctum::actingAs($actor, ['app:driver']);
            $this->postJson('/api/v1/driver/location/heartbeat', [
                'latitude' => 29.3,
                'longitude' => 47.9,
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('van_assignments', ['representative_user_id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }

    public function test_active_van_assignment_is_scoped_to_its_own_driver_and_never_grants_sidebar_authority(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $foreignUser = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_DRIVER')->firstOrFail();
        $owner->roles()->attach($role);
        $foreignUser->roles()->attach($role);

        $wholesale = app(WholesalePrincipal::class)->storeId();
        $driver = Driver::query()->create([
            'user_id' => $owner->id,
            'store_id' => $wholesale,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);
        $foreignDriver = Driver::query()->create([
            'user_id' => $foreignUser->id,
            'store_id' => $wholesale,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $ownVan = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'SYNTH-ROW16-OWN',
            'status' => 'active',
        ]);
        $foreignVan = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'SYNTH-ROW16-FOREIGN',
            'status' => 'active',
        ]);
        foreach ([[$ownVan, $driver], [$foreignVan, $foreignDriver]] as [$van, $assignedDriver]) {
            VanAssignment::query()->create([
                'public_id' => (string) Str::uuid(),
                'van_id' => $van->id,
                'driver_id' => $assignedDriver->id,
                'assignment_type' => 'primary',
                'status' => 'active',
                'effective_from' => now()->subMinutes(5),
            ]);
        }

        $runtime = app(VanRuntimeContextResolver::class);
        $authorized = $runtime->resolve($owner->fresh(), $ownVan->id);
        $forged = $runtime->resolve($owner->fresh(), $foreignVan->id);

        $this->assertSame($ownVan->id, $authorized['selected']['van_id']);
        $this->assertTrue($runtime->canUseRuntime($owner->fresh(), $authorized['selected']));
        $this->assertNull($forged['selected']);
        $this->assertSame('preferred_van_not_authorized', $forged['selection_reason']);
        $this->assertFalse($runtime->canUseRuntime($owner->fresh(), ['driver_id' => $foreignDriver->id]));
        $this->assertFalse($owner->fresh()->hasPermission('deliveries.b2c.execute'));

        $this->actingAs($owner->fresh())
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertForbidden();
    }
}
