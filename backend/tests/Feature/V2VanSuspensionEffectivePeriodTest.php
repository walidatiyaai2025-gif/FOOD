<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanSuspensionEffectivePeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspension_preserves_historical_and_planned_windows_but_ends_current_assignment(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-3487-MIXED']);
        $anchor = now()->startOfSecond();

        $historical = $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => $anchor->copy()->subDays(10),
            'effective_until' => $anchor->copy()->subDays(5),
        ]);
        $current = $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => $anchor->copy()->subDay(),
            'effective_until' => $anchor->copy()->addDay(),
        ]);
        $planned = $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => $anchor->copy()->addDays(2),
            'effective_until' => $anchor->copy()->addDays(5),
        ]);
        $plannedOpenEnded = $registry->assign($actor, $van, [
            'assignment_type' => 'backup',
            'effective_from' => $anchor->copy()->addDays(3),
        ]);

        $registry->suspend($van);

        $this->assertSame('suspended', $van->fresh()->status);
        foreach ([$historical, $current, $planned, $plannedOpenEnded] as $assignment) {
            $this->assertSame('ended', $assignment->fresh()->status);
        }

        $this->assertSame(
            $anchor->copy()->subDays(5)->toDateTimeString(),
            $historical->fresh()->effective_until->toDateTimeString(),
        );
        $this->assertSame(
            $anchor->copy()->addDays(5)->toDateTimeString(),
            $planned->fresh()->effective_until->toDateTimeString(),
        );
        $this->assertNull($plannedOpenEnded->fresh()->effective_until);

        $currentEnd = $current->fresh()->effective_until;
        $this->assertTrue($currentEnd->greaterThan($current->effective_from));
        $this->assertTrue($currentEnd->lessThan($anchor->copy()->addDay()));
        $this->assertTrue($planned->fresh()->effective_until->greaterThan($planned->effective_from));
        $this->assertCount(0, $registry->effectiveAssignments());
    }

    public function test_future_loaded_work_still_requires_explicit_target_without_date_corruption(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-3487-LOADED']);
        $target = $registry->createVan(['code' => 'V2-3487-TARGET']);
        $start = now()->startOfSecond()->addDays(3);
        $end = $start->copy()->addDays(2);

        $assignment = $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => $start,
            'effective_until' => $end,
            'loaded_work_count' => 2,
        ]);

        try {
            $registry->suspend($van);
            $this->fail('Loaded work must not be silently abandoned on Van suspension.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transfer_target', $exception->errors());
        }

        $this->assertSame('active', $van->fresh()->status);
        $this->assertSame('active', $assignment->fresh()->status);
        $this->assertSame($end->toDateTimeString(), $assignment->fresh()->effective_until->toDateTimeString());

        $registry->suspend($van, $target, 'planned maintenance');

        $ended = $assignment->fresh();
        $this->assertSame('ended', $ended->status);
        $this->assertSame($start->toDateTimeString(), $ended->effective_from->toDateTimeString());
        $this->assertSame($end->toDateTimeString(), $ended->effective_until->toDateTimeString());
        $this->assertSame($target->id, (int) $ended->transferred_to_van_id);
        $this->assertSame('planned maintenance', $ended->transfer_reason);
    }

    public function test_open_ended_current_assignment_closes_at_suspension_instead_of_remaining_effective(): void
    {
        $actor = User::factory()->create();
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-3487-CURRENT']);
        $started = now()->startOfSecond()->subDay();

        $assignment = $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => $started,
        ]);

        $registry->suspend($van);

        $ended = $assignment->fresh();
        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->effective_until);
        $this->assertTrue($ended->effective_until->greaterThan($ended->effective_from));
        $this->assertTrue($ended->effective_until->lessThanOrEqualTo(now()->addMinute()));
        $this->assertCount(0, $registry->effectiveAssignments());
    }
}
