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
 * V2 #2911 — historical Table 5 row 6 B2B_DRIVER (one inherited permission).
 *
 * All identities here are synthetic. A historic role snapshot never proves
 * a particular real user's current account type or Van assignment.
 */
final class V2ExportedAccountRow6VanBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_valid_wholesale_driver_assignment_retains_van_runtime_and_push_without_driver_app_authority(): void
    {
        [$user, $driver, $assignment] = $this->assignedDriver('B2B_DRIVER', 'b2b');
        $contexts = app(VanRuntimeContextResolver::class);

        $selected = $contexts->resolve($user)['selected'];
        $this->assertNotNull($selected);
        $this->assertSame($assignment->getKey(), $selected['assignment_id']);
        $this->assertSame($driver->getKey(), $selected['driver_id']);
        $this->assertTrue($contexts->canUseRuntime($user, $selected));
        $this->assertTrue($user->hasPermission('deliveries.b2b.execute'));
        $this->assertFalse($user->hasPermission('deliveries.b2c.execute'));
        $this->assertFalse($user->hasPermission('van.login'));

        Sanctum::actingAs($user, ['app:van']);
        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $token = 'synthetic-row6-wholesale-van';
        $this->postJson('/api/v1/push/devices', $this->pushPayload('van', $token))
            ->assertCreated()
            ->assertJsonPath('data.app', 'van');
        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => $user->id,
            'app' => 'van',
            'token_hash' => hash('sha256', $token),
            'revoked_at' => null,
        ]);

        // B2B Van fulfillment never grants Retail Driver execution.
        Sanctum::actingAs($user, ['app:driver']);
        $this->postJson('/api/v1/push/devices', $this->pushPayload('driver', 'synthetic-row6-b2b-no-driver'))
            ->assertForbidden();
    }

    public function test_b2c_only_driver_with_accidental_van_assignment_cannot_use_van_in_arabic_or_english(): void
    {
        [$user, $driver, $assignment] = $this->assignedDriver('B2C_DRIVER', 'b2c');
        $contexts = app(VanRuntimeContextResolver::class);

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            $actor = $user->fresh();
            $selected = $contexts->resolve($actor)['selected'];

            // The assignment exists, but it must never create a Van grant.
            $this->assertNotNull($selected);
            $this->assertSame($assignment->getKey(), $selected['assignment_id']);
            $this->assertSame($driver->getKey(), $selected['driver_id']);
            $this->assertTrue($actor->hasRole('B2C_DRIVER'));
            $this->assertFalse($actor->hasRole('B2B_DRIVER'));
            $this->assertFalse($contexts->canUseRuntime($actor, $selected));

            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();
            $token = 'synthetic-row6-retail-denied-'.$locale;
            $this->postJson('/api/v1/push/devices', $this->pushPayload('van', $token))
                ->assertForbidden();
            $this->assertDatabaseMissing('push_device_tokens', [
                'token_hash' => hash('sha256', $token),
            ]);
        }

        // The rightful Retail Driver app remains available; no destructive
        // role cleanup or user deactivation has taken place.
        Sanctum::actingAs($user->fresh(), ['app:driver']);
        $this->postJson('/api/v1/push/devices', $this->pushPayload('driver', 'synthetic-row6-retail-driver'))
            ->assertCreated()
            ->assertJsonPath('data.app', 'driver');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseHas('van_assignments', [
            'id' => $assignment->getKey(),
            'driver_id' => $driver->getKey(),
            'status' => 'active',
        ]);
    }

    public function test_stale_b2b_role_does_not_convert_b2c_driver_record_into_a_van_operator(): void
    {
        [$user, $driver, $assignment] = $this->assignedDriver('B2C_DRIVER', 'b2c');
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());
        $actor = $user->fresh();
        $contexts = app(VanRuntimeContextResolver::class);
        $selected = $contexts->resolve($actor)['selected'];

        $this->assertNotNull($selected);
        $this->assertSame($driver->getKey(), $selected['driver_id']);
        $this->assertTrue($actor->hasRole('B2B_DRIVER'));
        $this->assertTrue($actor->hasRole('B2C_DRIVER'));
        $this->assertFalse($contexts->canUseRuntime($actor, $selected));

        Sanctum::actingAs($actor, ['app:van']);
        $this->getJson('/api/v1/van/visits')->assertForbidden();
        $this->postJson('/api/v1/push/devices', $this->pushPayload('van', 'synthetic-row6-dual-role-denied'))
            ->assertForbidden();

        $this->assertDatabaseMissing('push_device_tokens', [
            'token_hash' => hash('sha256', 'synthetic-row6-dual-role-denied'),
        ]);
        $this->assertSame(2, DB::table('role_user')->where('user_id', $actor->getKey())->count());
        $this->assertDatabaseHas('drivers', [
            'id' => $driver->getKey(),
            'driver_type' => 'b2c',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('van_assignments', ['id' => $assignment->getKey(), 'status' => 'active']);
    }

    public function test_explicit_van_operator_remains_authorized_by_a_real_representative_assignment(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'V2-T5R6-REP-VAN',
            'status' => 'active',
        ]);
        VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->getKey(),
            'representative_user_id' => $actor->getKey(),
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinutes(3),
        ]);

        $actor = $actor->fresh();
        $this->assertTrue($actor->hasPermission('van.login'));
        $this->assertTrue(app(VanRuntimeContextResolver::class)->canUseRuntime(
            $actor,
            app(VanRuntimeContextResolver::class)->resolve($actor)['selected'],
        ));

        Sanctum::actingAs($actor, ['app:van']);
        $this->getJson('/api/v1/van/visits')->assertOk();
    }

    /** @return array{User, Driver, VanAssignment} */
    private function assignedDriver(string $roleCode, string $driverType): array
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Row6 '.$roleCode.' Driver',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->getKey(),
            'store_id' => $driverType === 'b2b'
                ? app(WholesalePrincipal::class)->storeId()
                : null,
            'driver_type' => $driverType,
            'is_available' => true,
            'is_active' => true,
        ]);
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'V2-T5R6-'.$roleCode.'-'.$user->getKey(),
            'status' => 'active',
        ]);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->getKey(),
            'driver_id' => $driver->getKey(),
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinutes(3),
        ]);

        return [$user->fresh(), $driver, $assignment];
    }

    /** @return array<string, string> */
    private function pushPayload(string $app, string $token): array
    {
        return [
            'app' => $app,
            'platform' => 'android',
            'environment' => 'production',
            'token' => $token,
            'install_id' => 'synthetic-row6-'.$app,
        ];
    }
}
