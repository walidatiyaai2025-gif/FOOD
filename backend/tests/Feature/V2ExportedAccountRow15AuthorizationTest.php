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
 * V2 #2920: confidential source Table 5, data row 15.
 * Synthetic fixtures only; never copy or infer a real account's present state.
 */
final class V2ExportedAccountRow15AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_legacy_b2b_driver_with_effective_van_assignment_cannot_use_runtime_or_register_push_device(): void
    {
        [$user, $driver, $assignment] = $this->legacyDriverWithEffectiveVanAssignment();
        $contexts = app(VanRuntimeContextResolver::class);

        $activeContext = $contexts->resolve($user)['selected'];
        $this->assertNotNull($activeContext);
        $this->assertSame($assignment->id, $activeContext['assignment_id']);
        $this->assertSame($driver->id, $activeContext['driver_id']);
        $this->assertTrue($contexts->canUseRuntime($user, $activeContext));

        $user->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            $inactive = $user->fresh();
            $context = $contexts->resolve($inactive)['selected'];

            // Legacy RBAC assignments may be retained for audit but never revive
            // an inactive account's Van compatibility authorization.
            $this->assertTrue($inactive->hasRole('B2B_DRIVER'));
            $this->assertFalse($inactive->hasPermission('deliveries.b2b.execute'));
            $this->assertFalse($inactive->hasPermission('van.login'));
            $this->assertNotNull($context);
            $this->assertFalse($contexts->canUseRuntime($inactive, $context));

            Sanctum::actingAs($inactive, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();

            $token = 'v2-synthetic-inactive-van-device-'.$locale;
            $this->postJson('/api/v1/push/devices', [
                'app' => 'van',
                'platform' => 'android',
                'environment' => 'production',
                'token' => $token,
                'install_id' => 'synthetic-row15-'.$locale,
            ])->assertForbidden();

            $this->assertDatabaseMissing('push_device_tokens', [
                'token_hash' => hash('sha256', $token),
            ]);

            // Van-only B2B execution does not grant Retail Driver access.
            Sanctum::actingAs($inactive, ['app:driver']);
            $this->postJson('/api/v1/driver/location/heartbeat', [
                'latitude' => 29.3,
                'longitude' => 47.9,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'B2B_DRIVER')->firstOrFail()->id,
        ]);
        $this->assertDatabaseHas('van_assignments', [
            'id' => $assignment->id,
            'driver_id' => $driver->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
        ]);
    }

    public function test_expired_assignment_cannot_restore_van_access_to_active_legacy_driver(): void
    {
        [$user, , $assignment] = $this->legacyDriverWithEffectiveVanAssignment();
        $contexts = app(VanRuntimeContextResolver::class);

        $this->assertTrue($contexts->canUseRuntime($user, $contexts->resolve($user)['selected']));

        $assignment->forceFill(['effective_until' => now()->subMinute()])->save();

        $this->assertNull($contexts->resolve($user)['selected']);
        $this->assertFalse($contexts->canUseRuntime($user));
        $this->assertFalse($user->hasPermission('van.login'));

        Sanctum::actingAs($user, ['app:van']);
        $this->getJson('/api/v1/van/visits')->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'B2B_DRIVER')->firstOrFail()->id,
        ]);
    }

    /** @return array{User, Driver, VanAssignment} */
    private function legacyDriverWithEffectiveVanAssignment(): array
    {
        $user = User::factory()->create([
            'name' => 'Synthetic V2 Legacy Role Fixture',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'SYNTH-ROW15-VAN',
            'status' => 'active',
        ]);

        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'driver_id' => $driver->id,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinutes(5),
        ]);

        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->assertFalse($user->hasPermission('deliveries.b2c.execute'));

        return [$user->fresh(), $driver, $assignment];
    }
}
