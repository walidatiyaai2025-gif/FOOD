<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VanRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_are_effective_dated_and_history_is_not_rewritten(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-A']);

        $past = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'effective_until' => '2026-02-01T00:00:00Z',
            'territory_key' => 'north',
        ]);
        $current = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-02-01T00:00:00Z',
            'territory_key' => 'south',
        ]);

        $this->assertSame('north', $past->fresh()->territory_key);
        $this->assertSame('south', $current->fresh()->territory_key);
        $this->assertCount(1, $service->effectiveAssignments('2026-01-15T00:00:00Z'));
        $this->assertSame('north', $service->effectiveAssignments('2026-01-15T00:00:00Z')->first()->territory_key);
        $this->assertSame('south', $service->effectiveAssignments('2026-03-01T00:00:00Z')->first()->territory_key);
    }

    public function test_overlapping_primary_assignments_are_rejected(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'VAN-A']);

        $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
        ]);

        $this->expectException(ValidationException::class);
        $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-02T00:00:00Z',
        ]);
    }

    public function test_loaded_work_requires_explicit_transfer_before_suspension(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'VAN-A']);
        $target = $service->createVan(['code' => 'VAN-B']);

        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 3,
        ]);

        try {
            $service->suspend($source);
            $this->fail('Suspension should require an explicit transfer target.');
        } catch (ValidationException) {
            $this->assertSame('active', $source->fresh()->status);
            $this->assertSame('active', $assignment->fresh()->status);
        }

        $service->suspend($source, $target, 'vehicle maintenance');

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame($target->id, $assignment->fresh()->transferred_to_van_id);
        $this->assertSame('vehicle maintenance', $assignment->fresh()->transfer_reason);
    }
}
