<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanTerritoryEffectiveWindowGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_rejects_assignment_longer_than_territory_without_audit(): void
    {
        $actor = User::factory()->create();
        $territory = $this->territory('WINDOW-BOUNDED', '2030-02-01 00:00:00');
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-WINDOW-DENIED']);

        try {
            $service->assign($actor, $van, [
                'assignment_type' => 'primary',
                'territory_key' => $territory->code,
                'effective_from' => '2030-01-15T00:00:00Z',
                'effective_until' => '2030-02-02T00:00:00Z',
            ]);
            $this->fail('Assignment cannot continue beyond Service Territory expiration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('territory_key', $exception->errors());
        }

        $this->assertDatabaseMissing('van_assignments', ['van_id' => $van->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'van.assignment.created',
            'user_id' => $actor->id,
        ]);
    }

    public function test_open_ended_assignment_cannot_outlive_bounded_territory(): void
    {
        $actor = User::factory()->create();
        $territory = $this->territory('WINDOW-ENDS', '2030-02-01 00:00:00');
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-WINDOW-OPEN-DENIED']);

        try {
            $service->assign($actor, $van, [
                'assignment_type' => 'primary',
                'territory_key' => $territory->code,
                'effective_from' => '2030-01-15T00:00:00Z',
            ]);
            $this->fail('Open-ended assignment requires an open-ended territory.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('territory_key', $exception->errors());
        }

        $this->assertDatabaseMissing('van_assignments', ['van_id' => $van->id]);
    }

    public function test_edit_cannot_extend_past_territory_expiration_or_mutate_audit(): void
    {
        $actor = User::factory()->create();
        $territory = $this->territory('WINDOW-EDIT', '2030-02-01 00:00:00');
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-WINDOW-EDIT']);
        $assignment = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'territory_key' => $territory->code,
            'effective_from' => '2030-01-15T00:00:00Z',
            'effective_until' => '2030-01-20T00:00:00Z',
        ]);

        try {
            $service->updateAssignment($actor, $assignment, [
                'van_id' => $van->id,
                'assignment_type' => 'primary',
                'status' => 'active',
                'territory_key' => $territory->code,
                'effective_from' => '2030-01-15T00:00:00Z',
                'effective_until' => '2030-02-02T00:00:00Z',
                'loaded_work_count' => 4,
            ]);
            $this->fail('Edit cannot extend assignment past territory expiration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('territory_key', $exception->errors());
        }

        $this->assertDatabaseHas('van_assignments', [
            'id' => $assignment->id,
            'territory_key' => $territory->code,
            'effective_until' => '2030-01-20 00:00:00',
            'loaded_work_count' => 0,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'van.assignment.updated',
            'auditable_id' => $assignment->id,
        ]);
    }

    public function test_exact_half_open_boundary_and_unbounded_territory_are_valid(): void
    {
        $actor = User::factory()->create();
        $bounded = $this->territory('WINDOW-EXACT', '2030-02-01 00:00:00');
        $unbounded = $this->territory('WINDOW-UNBOUNDED', null);
        $service = app(VanRegistryService::class);
        $vanA = $service->createVan(['code' => 'V2-WINDOW-EXACT']);
        $vanB = $service->createVan(['code' => 'V2-WINDOW-UNBOUNDED']);

        $first = $service->assign($actor, $vanA, [
            'assignment_type' => 'primary',
            'territory_key' => $bounded->code,
            'effective_from' => '2030-01-01T00:00:00Z',
            'effective_until' => '2030-02-01T00:00:00Z',
        ]);
        $second = $service->assign($actor, $vanB, [
            'assignment_type' => 'primary',
            'territory_key' => $unbounded->code,
            'effective_from' => '2030-01-01T00:00:00Z',
        ]);

        $this->assertSame($bounded->code, $first->territory_key);
        $this->assertSame($unbounded->code, $second->territory_key);
        $this->assertNull($second->effective_until);
    }

    public function test_future_and_inactive_territory_are_rejected(): void
    {
        $actor = User::factory()->create();
        $future = $this->territory('WINDOW-FUTURE', null, 'active', '2030-03-01 00:00:00');
        $inactive = $this->territory('WINDOW-INACTIVE', null, 'inactive');
        $service = app(VanRegistryService::class);

        foreach ([$future, $inactive] as $territory) {
            $van = $service->createVan(['code' => 'V2-'.$territory->code]);

            try {
                $service->assign($actor, $van, [
                    'assignment_type' => 'primary',
                    'territory_key' => $territory->code,
                    'effective_from' => '2030-01-15T00:00:00Z',
                    'effective_until' => '2030-01-20T00:00:00Z',
                ]);
                $this->fail('Future or inactive territory must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('territory_key', $exception->errors());
            }

            $this->assertDatabaseMissing('van_assignments', ['van_id' => $van->id]);
        }
    }

    public function test_unscoped_assignment_remains_supported(): void
    {
        $actor = User::factory()->create();
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-WINDOW-NO-TERRITORY']);

        $assignment = $service->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2030-01-15T00:00:00Z',
        ]);

        $this->assertSame('active', $assignment->status);
        $this->assertNull($assignment->territory_key);
    }

    public function test_territory_window_rejection_has_arabic_and_english_messages(): void
    {
        $actor = User::factory()->create();
        $territory = $this->territory('WINDOW-LOCALE', '2030-02-01 00:00:00');
        $service = app(VanRegistryService::class);
        $van = $service->createVan(['code' => 'V2-WINDOW-LOCALE']);
        $originalLocale = app()->getLocale();

        try {
            $messages = [];
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);

                try {
                    $service->assign($actor, $van, [
                        'assignment_type' => 'primary',
                        'territory_key' => $territory->code,
                        'effective_from' => '2030-01-15T00:00:00Z',
                    ]);
                    $this->fail('Missing territory coverage must reject under both locales.');
                } catch (ValidationException $exception) {
                    $messages[$locale] = $exception->errors()['territory_key'][0] ?? null;
                    $this->assertSame(__('van_assignment_guard.territory_window_required'), $messages[$locale]);
                }
            }

            $this->assertNotSame($messages['en'], $messages['ar']);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    private function territory(
        string $code,
        ?string $effectiveUntil,
        string $status = 'active',
        string $effectiveFrom = '2030-01-01 00:00:00',
    ): ServiceTerritory {
        $country = GeographyNode::query()->firstOrCreate(
            ['code' => 'v2-window-country'],
            [
                'type' => 'country',
                'name_ar' => 'مصر',
                'name_en' => 'Egypt',
                'country_code' => 'EGT',
                'is_active' => true,
            ],
        );

        return ServiceTerritory::query()->create([
            'code' => $code,
            'name_ar' => $code,
            'name_en' => $code,
            'country_node_id' => $country->id,
            'status' => $status,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
        ]);
    }
}
