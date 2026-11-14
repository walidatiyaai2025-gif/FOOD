<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanSuspensionTransferTargetGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_loaded_work_cannot_transfer_to_the_same_van(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-TARGET-SELF']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 3,
        ]);

        try {
            $service->suspend($source, $source, 'unsafe self transfer');
            $this->fail('Loaded work may not be assigned to its suspended source Van.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transfer_target', $exception->errors());
        }

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('active', $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->transferred_to_van_id);
    }

    public function test_loaded_work_cannot_transfer_to_a_suspended_van(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-TARGET-SOURCE']);
        $inactive = $service->createVan(['code' => 'V2-TARGET-INACTIVE', 'status' => 'suspended']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 5,
        ]);

        try {
            $service->suspend($source, $inactive, 'target suspended');
            $this->fail('Loaded work may not be transferred to a suspended Van.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transfer_target', $exception->errors());
        }

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('suspended', $inactive->fresh()->status);
        $this->assertSame('active', $assignment->fresh()->status);
        $this->assertSame(5, (int) $assignment->fresh()->loaded_work_count);
        $this->assertNull($assignment->fresh()->transferred_to_van_id);
    }

    public function test_distinct_active_target_is_accepted_even_with_lower_id(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        // Create the target first to exercise ascending target/source locking.
        $target = $service->createVan(['code' => 'V2-TARGET-LOWER']);
        $source = $service->createVan(['code' => 'V2-SOURCE-HIGHER']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 4,
        ]);
        $this->assertLessThan($source->id, $target->id);

        $service->suspend($source, $target, 'vehicle repair');

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame($target->id, $assignment->fresh()->transferred_to_van_id);
        $this->assertSame('vehicle repair', $assignment->fresh()->transfer_reason);
    }

    public function test_unloaded_van_can_still_be_suspended_without_a_target(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-TARGET-EMPTY']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 0,
        ]);

        $service->suspend($source);

        $this->assertSame('suspended', $source->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->transferred_to_van_id);
    }

    public function test_loaded_without_target_is_localized_in_arabic_and_english(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $source = $service->createVan(['code' => 'V2-TARGET-I18N']);
        $assignment = $service->assign($actor, $source, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
            'loaded_work_count' => 2,
        ]);
        $originalLocale = app()->getLocale();

        try {
            $messages = [];
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);

                try {
                    $service->suspend($source);
                    $this->fail('Loaded work requires a valid transfer target.');
                } catch (ValidationException $exception) {
                    $messages[$locale] = $exception->errors()['transfer_target'][0] ?? null;
                    $this->assertSame(__('van_assignment_guard.active_transfer_target_required'), $messages[$locale]);
                }
            }
            $this->assertNotSame($messages['en'], $messages['ar']);
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('active', $assignment->fresh()->status);
    }
}
