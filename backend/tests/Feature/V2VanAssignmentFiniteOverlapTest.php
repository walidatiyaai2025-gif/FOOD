<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanAssignmentFiniteOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shorter_existing_primary_window_blocks_a_later_finite_overlap_without_audit_writes(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P3480-ONE']);

        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);

        // Existing [Jan 10, Jan 20) overlaps candidate [Jan 15, Jan 25),
        // even though the existing end is earlier than the candidate end.
        try {
            $registry->assign($actor, $van, [
                'assignment_type' => 'primary',
                'effective_from' => '2026-01-15T00:00:00Z',
                'effective_until' => '2026-01-25T00:00:00Z',
            ]);
            $this->fail('An overlapping primary Van window must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $this->assertDatabaseCount('van_assignments', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'van.assignment.created')->count());
    }

    public function test_adjacent_primary_windows_in_both_directions_remain_valid(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P3480-ADJACENT']);

        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-20T00:00:00Z',
            'effective_until' => '2026-01-25T00:00:00Z',
        ]);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-05T00:00:00Z',
            'effective_until' => '2026-01-10T00:00:00Z',
        ]);

        $this->assertDatabaseCount('van_assignments', 3);
    }

    public function test_shorter_existing_primary_territory_window_blocks_other_van_without_audit_writes(): void
    {
        $actor = User::factory()->create();
        $this->territory('P3480-NORTH');
        $registry = app(VanRegistryService::class);
        $first = $registry->createVan(['code' => 'P3480-TERRITORY-A']);
        $second = $registry->createVan(['code' => 'P3480-TERRITORY-B']);

        $registry->assign($actor, $first, [
            'assignment_type' => 'primary',
            'territory_key' => 'P3480-NORTH',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);

        try {
            $registry->assign($actor, $second, [
                'assignment_type' => 'primary',
                'territory_key' => 'P3480-NORTH',
                'effective_from' => '2026-01-15T00:00:00Z',
                'effective_until' => '2026-01-25T00:00:00Z',
            ]);
            $this->fail('Two Vans cannot own the same territory for overlapping windows.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('territory_key', $exception->errors());
        }

        $this->assertDatabaseCount('van_assignments', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'van.assignment.created')->count());
    }

    public function test_open_ended_primary_cannot_overlap_a_historical_finite_window(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P3480-OPEN-CONFLICT']);

        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);

        // Old create logic compared existing.end against now(), thereby
        // missing a historical overlap when a new window has no upper bound.
        try {
            $registry->assign($actor, $van, [
                'assignment_type' => 'primary',
                'effective_from' => '2026-01-15T00:00:00Z',
            ]);
            $this->fail('An open-ended new window must not overlap a finite old window.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $this->assertDatabaseCount('van_assignments', 1);
    }

    public function test_adjacent_primary_territory_windows_for_different_vans_remain_valid(): void
    {
        $actor = User::factory()->create();
        $this->territory('P3480-SOUTH');
        $registry = app(VanRegistryService::class);
        $first = $registry->createVan(['code' => 'P3480-SOUTH-A']);
        $second = $registry->createVan(['code' => 'P3480-SOUTH-B']);

        $registry->assign($actor, $first, [
            'assignment_type' => 'primary',
            'territory_key' => 'P3480-SOUTH',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);
        $registry->assign($actor, $second, [
            'assignment_type' => 'primary',
            'territory_key' => 'P3480-SOUTH',
            'effective_from' => '2026-01-20T00:00:00Z',
            'effective_until' => '2026-01-25T00:00:00Z',
        ]);

        $this->assertDatabaseCount('van_assignments', 2);
    }

    public function test_open_ended_primary_after_historical_end_is_valid(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P3480-OPEN-ENDED']);

        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-10T00:00:00Z',
            'effective_until' => '2026-01-20T00:00:00Z',
        ]);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-20T00:00:00Z',
        ]);

        $this->assertDatabaseCount('van_assignments', 2);
    }

    private function territory(string $code): void
    {
        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'p3480-country',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => 'EGT',
            'is_active' => true,
        ]);
        ServiceTerritory::query()->create([
            'code' => $code,
            'name_ar' => $code,
            'name_en' => $code,
            'country_node_id' => $country->id,
            'status' => 'active',
            'effective_from' => '2025-01-01 00:00:00',
        ]);
    }
}
