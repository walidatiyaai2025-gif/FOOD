<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Models\VanVisit;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2UserOpenAssignmentOffboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_pending_driver_delivery_blocks_deactivation_without_mutating_account_tokens_or_assignments(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2C_DRIVER');
        $assignment = $this->driverAssignment($target, 'assigned');
        $token = $target->createToken('pending-delivery');

        Sanctum::actingAs($admin);

        $this->archive($target)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignment->id,
            'status' => 'assigned',
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_active_van_representative_or_driver_assignment_blocks_deactivation(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $representative = $this->withRole('VAN_OPERATOR');
        $driverUser = $this->withRole('B2B_DRIVER');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'driver_type' => 'b2b',
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->vanAssignment($representative->id, null);
        $this->vanAssignment(null, $driver->id);

        Sanctum::actingAs($admin);

        foreach ([$representative, $driverUser] as $target) {
            $this->archive($target)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('is_active');
            $this->assertTrue((bool) $target->fresh()->is_active);
        }

        $this->assertSame(2, DB::table('van_assignments')->where('status', 'active')->count());
    }

    public function test_planned_van_visit_blocks_deactivation_in_arabic_and_english(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('VAN_OPERATOR');
        VanVisit::query()->create([
            'actor_user_id' => $target->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'status' => 'planned',
            'planned_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            Sanctum::actingAs($admin->fresh());

            $this->archive($target)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('is_active')
                ->assertJsonPath(
                    'errors.is_active.0',
                    trans('admin.security.errors.open_operational_assignments', [], $locale),
                );
        }

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertDatabaseHas('van_visits', ['actor_user_id' => $target->id, 'status' => 'planned']);
    }

    public function test_completed_operational_work_does_not_indefinitely_block_authorized_archive(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('VAN_OPERATOR');

        $this->vanAssignment($target->id, null, 'ended');
        VanVisit::query()->create([
            'actor_user_id' => $target->id,
            'customer_type' => 'b2b',
            'customer_id' => 10002,
            'status' => 'completed_no_order',
            'completed_at' => now()->subHour(),
            'closed_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->archive($target)->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $target->fresh()->is_active);
        $this->assertDatabaseHas('van_visits', ['actor_user_id' => $target->id, 'status' => 'completed_no_order']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_completed_driver_assignment_is_retained_without_blocking_authorized_archive(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2C_DRIVER');
        $assignment = $this->driverAssignment($target, 'delivered');
        $assignment->forceFill(['completed_at' => now()])->save();

        Sanctum::actingAs($admin);

        $this->archive($target)->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $target->fresh()->is_active);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignment->id,
            'status' => 'delivered',
        ]);
    }

    public function test_unauthorized_actor_cannot_archive_user_with_open_work(): void
    {
        $viewer = $this->withRole('B2B_ADMIN');
        $target = $this->withRole('VAN_OPERATOR');

        VanVisit::query()->create([
            'actor_user_id' => $target->id,
            'customer_type' => 'b2b',
            'customer_id' => 10003,
            'status' => 'started',
            'started_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->archive($target)->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function archive(User $target): TestResponse
    {
        return $this->patchJson("/api/v1/admin/security/users/{$target->id}/status", [
            'is_active' => false,
            'reason' => 'Reviewed operator offboarding',
            'impact_reviewed' => true,
            'confirm_email' => $target->email,
        ]);
    }

    private function vanAssignment(?int $representativeId, ?int $driverId, string $status = 'active'): void
    {
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'V2-OFFBOARD-'.Str::random(10),
            'status' => 'active',
        ]);

        DB::table('van_assignments')->insert([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'representative_user_id' => $representativeId,
            'driver_id' => $driverId,
            'assignment_type' => 'primary',
            'status' => $status,
            'effective_from' => now()->subDay(),
            'effective_until' => $status === 'ended' ? now()->subHour() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function driverAssignment(User $target, string $status): DriverAssignment
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-OFFBOARD-RETAIL',
            'name' => 'Offboarding retail store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerUser = User::factory()->create();
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => 'Offboarding fixture customer',
            'email' => $customerUser->email,
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => 'V2-OFFBOARD-ORDER',
            'channel' => 'b2c',
            'status' => 'ready',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);
        $driver = Driver::query()->create([
            'user_id' => $target->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_active' => true,
            'is_available' => true,
        ]);

        return DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'assignment_type' => 'b2c',
            'status' => $status,
            'assigned_at' => now(),
        ]);
    }
}
