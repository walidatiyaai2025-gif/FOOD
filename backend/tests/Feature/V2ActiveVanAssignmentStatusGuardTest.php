<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2ActiveVanAssignmentStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_van_cannot_receive_a_new_active_assignment(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-GUARD-SUSPENDED', 'status' => 'suspended']);

        try {
            $service->assign($actor, $van, [
                'assignment_type' => 'primary',
                'effective_from' => '2030-01-01T00:00:00Z',
            ]);
            $this->fail('A suspended Van must reject new assignments.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('van_id', $exception->errors());
        }

        $this->assertDatabaseMissing('van_assignments', ['van_id' => $van->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'van.assignment.created',
            'user_id' => $actor->id,
        ]);
    }

    public function test_edit_cannot_move_an_active_assignment_to_a_suspended_van(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $active = $service->createVan(['code' => 'V2-GUARD-ACTIVE']);
        $suspended = $service->createVan(['code' => 'V2-GUARD-INACTIVE', 'status' => 'suspended']);
        $assignment = $service->assign($actor, $active, [
            'assignment_type' => 'primary',
            'effective_from' => '2030-01-01T00:00:00Z',
        ]);

        try {
            $service->updateAssignment($actor, $assignment, [
                'van_id' => $suspended->id,
                'assignment_type' => 'primary',
                'status' => 'active',
                'effective_from' => '2030-01-02T00:00:00Z',
                'loaded_work_count' => 5,
            ]);
            $this->fail('An assignment cannot be moved to a suspended Van.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('van_id', $exception->errors());
        }

        $this->assertDatabaseHas('van_assignments', [
            'id' => $assignment->id,
            'van_id' => $active->id,
            'status' => 'active',
            'loaded_work_count' => 0,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'van.assignment.updated',
            'auditable_id' => $assignment->id,
        ]);
    }

    public function test_suspension_ends_existing_assignment_and_blocks_reactivation_and_creation(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-GUARD-SUSPEND-THEN-ASSIGN']);
        $assignment = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-01-01T00:00:00Z',
        ]);

        $service->suspend($van);
        $this->assertSame('suspended', $van->fresh()->status);
        $this->assertSame('ended', $assignment->fresh()->status);

        foreach (['create', 'reactivate'] as $operation) {
            try {
                if ($operation === 'create') {
                    $service->assign($actor, $van, [
                        'assignment_type' => 'backup',
                        'effective_from' => '2030-01-01T00:00:00Z',
                    ]);
                } else {
                    $service->updateAssignment($actor, $assignment, [
                        'van_id' => $van->id,
                        'assignment_type' => 'primary',
                        'status' => 'active',
                        'effective_from' => '2026-01-01T00:00:00Z',
                    ]);
                }
                $this->fail('A suspended Van must not accept assignment writes.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('van_id', $exception->errors());
            }
        }

        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame(1, $van->assignments()->count());
    }

    public function test_active_van_still_allows_create_and_edit_with_representative_only(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-GUARD-VALID']);

        $assignment = $service->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => '2030-01-01T00:00:00Z',
        ]);

        $this->assertSame('active', $assignment->status);
        $updated = $service->updateAssignment($actor, $assignment, [
            'van_id' => $van->id,
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => '2030-01-02T00:00:00Z',
            'loaded_work_count' => 2,
        ]);

        $this->assertSame('active', $updated->status);
        $this->assertSame($van->id, $updated->van_id);
        $this->assertSame(2, (int) $updated->loaded_work_count);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'van.assignment.updated',
            'auditable_id' => $assignment->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_validation_message_has_arabic_and_english_translations(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-GUARD-I18N', 'status' => 'suspended']);
        $previousLocale = app()->getLocale();

        try {
            $messages = [];
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);
                try {
                    $service->assign($actor, $van, [
                        'assignment_type' => 'primary',
                        'effective_from' => '2030-01-01T00:00:00Z',
                    ]);
                    $this->fail('A suspended Van must be rejected under both locales.');
                } catch (ValidationException $exception) {
                    $messages[$locale] = $exception->errors()['van_id'][0] ?? null;
                    $this->assertSame(__('van_assignment_guard.active_van_required'), $messages[$locale]);
                }
            }
            $this->assertNotSame($messages['en'], $messages['ar']);
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
