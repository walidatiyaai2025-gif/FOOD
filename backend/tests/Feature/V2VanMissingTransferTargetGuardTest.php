<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanMissingTransferTargetGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_target_for_loaded_van_is_rejected_in_both_locales_without_mutation(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-DELETED-TARGET-SOURCE']);
        $staleTarget = $service->createVan(['code' => 'V2-DELETED-TARGET-OLD']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 3,
        ]);

        // Simulate an authorized caller holding a model whose target row
        // disappeared before suspend() could lock/validate it.
        $staleTarget->delete();
        $this->assertNull($staleTarget->fresh());

        $originalLocale = app()->getLocale();
        $messages = [];

        try {
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);

                try {
                    $service->suspend($source, $staleTarget, 'target vanished');
                    $this->fail('Loaded work must reject a deleted destination.');
                } catch (ValidationException $exception) {
                    $messages[$locale] = $exception->errors()['transfer_target'][0] ?? null;
                    $this->assertSame(
                        __('van_assignment_guard.active_transfer_target_required'),
                        $messages[$locale],
                    );
                }

                $freshAssignment = $assignment->fresh();
                $this->assertSame('active', $source->fresh()->status);
                $this->assertSame('active', $freshAssignment->status);
                $this->assertSame(3, (int) $freshAssignment->loaded_work_count);
                $this->assertNull($freshAssignment->effective_until);
                $this->assertNull($freshAssignment->transferred_to_van_id);
                $this->assertNull($freshAssignment->transfer_reason);
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertNotSame($messages['en'], $messages['ar']);
    }

    public function test_unloaded_van_can_ignore_a_deleted_optional_target(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-DELETED-EMPTY-SOURCE']);
        $staleTarget = $service->createVan(['code' => 'V2-DELETED-EMPTY-OLD']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 0,
        ]);

        $staleTarget->delete();

        $service->suspend($source, $staleTarget);

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->transferred_to_van_id);
        $this->assertNull($assignment->fresh()->transfer_reason);
    }

    public function test_deleted_source_remains_a_not_found_error(): void
    {
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-DELETED-SOURCE-ROW']);
        $source->delete();

        $this->expectException(ModelNotFoundException::class);
        $service->suspend($source);
    }

    public function test_loaded_van_still_accepts_distinct_active_destination(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $target = $service->createVan(['code' => 'V2-ACTIVE-LOWER-TARGET']);
        $source = $service->createVan(['code' => 'V2-ACTIVE-HIGHER-SOURCE']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 2,
        ]);

        $service->suspend($source, $target, 'approved transfer');

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame($target->id, $assignment->fresh()->transferred_to_van_id);
        $this->assertSame('approved transfer', $assignment->fresh()->transfer_reason);
    }
}
