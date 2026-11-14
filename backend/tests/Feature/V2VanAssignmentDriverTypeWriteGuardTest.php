<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VanAssignment;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanAssignmentDriverTypeWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private VanRegistryService $registry;

    private User $actor;

    private int $storeSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->registry = app(VanRegistryService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_active_wholesale_driver_and_representative_only_assignments_are_allowed(): void
    {
        $van = $this->registry->createVan(['code' => 'V2-GUARD-POSITIVE']);
        $driverId = $this->driver('b2b');

        $assignment = $this->registry->assign($this->actor, $van, [
            'driver_id' => $driverId,
            'effective_from' => now()->subMinute(),
        ]);

        $this->assertSame($driverId, (int) $assignment->driver_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'van.assignment.created',
            'auditable_id' => $assignment->id,
        ]);

        $this->registry->updateAssignment($this->actor, $assignment, [
            'driver_id' => $driverId,
            'status' => 'active',
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $representativeVan = $this->registry->createVan(['code' => 'V2-GUARD-REPRESENTATIVE']);
        $representative = $this->registry->assign($this->actor, $representativeVan, [
            'representative_user_id' => User::factory()->create(['is_active' => true])->id,
            'effective_from' => now()->subMinute(),
        ]);
        $this->assertNull($representative->driver_id);
    }

    public function test_retail_inactive_and_unknown_driver_creation_is_rejected_without_writes_in_both_locales(): void
    {
        $van = $this->registry->createVan(['code' => 'V2-GUARD-NEGATIVE']);
        $retail = $this->driver('b2c');
        $inactive = $this->driver('b2b', active: false);
        $disabledUser = $this->driver('b2b', userActive: false);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$retail, $inactive, $disabledUser, 99999999] as $driverId) {
                try {
                    $this->registry->assign($this->actor, $van, [
                        'driver_id' => $driverId,
                        'effective_from' => now()->subMinute(),
                    ]);
                    $this->fail('Invalid Van Driver was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertSame(
                        __('van_assignment_guard.b2b_driver_required'),
                        $exception->errors()['driver_id'][0] ?? null,
                    );
                }
                $this->assertDatabaseCount('van_assignments', 0);
                $this->assertDatabaseMissing('audit_logs', ['event' => 'van.assignment.created']);
            }
        }
    }

    public function test_retail_and_disabled_driver_cannot_replace_a_valid_active_van_assignee(): void
    {
        $van = $this->registry->createVan(['code' => 'V2-GUARD-EDIT']);
        $originalDriver = $this->driver('b2b');
        $retailDriver = $this->driver('b2c');
        $disabledDriver = $this->driver('b2b', active: false);

        $assignment = $this->registry->assign($this->actor, $van, [
            'driver_id' => $originalDriver,
            'effective_from' => now()->subMinute(),
        ]);

        foreach ([$retailDriver, $disabledDriver] as $driverId) {
            try {
                $this->registry->updateAssignment($this->actor, $assignment, [
                    'driver_id' => $driverId,
                    'status' => 'active',
                    'assignment_type' => 'primary',
                    'effective_from' => now()->subMinute(),
                ]);
                $this->fail('Invalid Driver replacement was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('driver_id', $exception->errors());
            }
        }

        $this->assertSame($originalDriver, (int) $assignment->fresh()->driver_id);
        $this->assertSame('active', $assignment->fresh()->status);
        $this->assertDatabaseCount('van_assignments', 1);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'van.assignment.updated']);
    }

    public function test_inconsistent_legacy_assignment_can_be_ended_without_reactivating_retail_driver(): void
    {
        $van = $this->registry->createVan(['code' => 'V2-GUARD-LEGACY']);
        $driver = $this->driver('b2b');
        $retail = $this->driver('b2c');

        $assignment = $this->registry->assign($this->actor, $van, [
            'driver_id' => $driver,
            'effective_from' => now()->subMinute(),
        ]);
        // Reproduce a historical invalid association without calling the guarded write path.
        $assignment->forceFill(['driver_id' => $retail])->save();

        $ended = $this->registry->updateAssignment($this->actor, $assignment, [
            'driver_id' => $retail,
            'status' => 'ended',
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $this->assertSame('ended', $ended->status);
        $this->assertSame($retail, (int) $ended->driver_id);
    }

    private function driver(string $type, bool $active = true, bool $userActive = true): int
    {
        $user = User::factory()->create(['is_active' => $userActive]);
        $storeTypeId = DB::table('store_types')->where('code', strtoupper($type))->value('id');
        $this->assertNotNull($storeTypeId);
        $this->storeSequence++;

        $storeId = DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-GUARD-STORE-'.$this->storeSequence,
            'name' => 'V2 Driver Guard Store '.$this->storeSequence,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('drivers')->insertGetId([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => $type,
            'is_available' => true,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
