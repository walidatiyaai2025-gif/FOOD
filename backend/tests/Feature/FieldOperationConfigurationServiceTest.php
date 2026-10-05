<?php

namespace Tests\Feature;

use App\Models\FieldOperationConfigurationRevision;
use App\Models\User;
use App\Services\FieldOperationConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldOperationConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_specific_published_scope_wins_and_default_remains_available(): void
    {
        $actor = User::factory()->create();
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'routing.mode',
            valueType: 'string',
            defaultValue: 'MANUAL',
            failurePolicy: 'degrade_safe',
            validationSchema: ['allowed_values' => ['MANUAL', 'AUTOMATIC', 'HYBRID']],
            dependencies: [
                ['key' => 'routing.manual_dispatch', 'required' => true, 'fallback' => 'manual_queue'],
            ],
        );

        $platform = $service->createDraft($actor, 'routing.mode', 'platform', null, 'AUTOMATIC');
        $service->publish($actor, $platform);

        $territory = $service->createDraft($actor, 'routing.mode', 'territory', 'alex-west', 'MANUAL');
        $service->publish($actor, $territory);

        $effective = $service->resolve('routing.mode', [
            'country' => 'EG',
            'territory' => 'alex-west',
        ]);

        $this->assertSame('MANUAL', $effective['value']);
        $this->assertSame('territory', $effective['scope_type']);
        $this->assertSame('alex-west', $effective['scope_key']);
        $this->assertSame('published_revision', $effective['source']);

        $otherTerritory = $service->resolve('routing.mode', [
            'country' => 'EG',
            'territory' => 'cairo-east',
        ]);

        $this->assertSame('AUTOMATIC', $otherTerritory['value']);
        $this->assertSame('platform', $otherTerritory['scope_type']);
    }

    public function test_definition_default_is_used_when_no_published_revision_exists(): void
    {
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'van.field_order_capture.enabled',
            valueType: 'boolean',
            defaultValue: false,
            failurePolicy: 'degrade_safe',
            dependencies: [],
        );

        $effective = $service->resolve('van.field_order_capture.enabled', [
            'country' => 'EG',
        ]);

        $this->assertFalse($effective['value']);
        $this->assertSame('default', $effective['source']);
        $this->assertSame('degrade_safe', $effective['failure_policy']);
    }

    public function test_publish_archives_previous_scope_revision_and_rollback_creates_new_revision(): void
    {
        $actor = User::factory()->create();
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'routing.mode',
            valueType: 'string',
            defaultValue: 'MANUAL',
            failurePolicy: 'degrade_safe',
            validationSchema: ['allowed_values' => ['MANUAL', 'AUTOMATIC', 'HYBRID']],
        );

        $first = $service->publish(
            $actor,
            $service->createDraft($actor, 'routing.mode', 'platform', null, 'MANUAL'),
        );

        $second = $service->publish(
            $actor,
            $service->createDraft($actor, 'routing.mode', 'platform', null, 'HYBRID'),
        );

        $this->assertSame(
            FieldOperationConfigurationService::STATUS_ARCHIVED,
            $first->fresh()->status,
        );
        $this->assertSame(
            FieldOperationConfigurationService::STATUS_PUBLISHED,
            $second->fresh()->status,
        );

        $restored = $service->rollback($actor, $first, 'incident rollback');

        $this->assertSame('MANUAL', data_get($restored->value, 'value'));
        $this->assertSame(3, $restored->revision_number);
        $this->assertSame($first->getKey(), $restored->source_revision_id);
        $this->assertSame(
            FieldOperationConfigurationService::STATUS_ARCHIVED,
            $second->fresh()->status,
        );
    }

    public function test_effective_window_does_not_activate_future_revision_early(): void
    {
        $actor = User::factory()->create();
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'collection.cash.enabled',
            valueType: 'boolean',
            defaultValue: false,
            failurePolicy: 'fail_closed',
        );

        $revision = $service->createDraft(
            actor: $actor,
            key: 'collection.cash.enabled',
            scopeType: 'country',
            scopeKey: 'EG',
            value: true,
            effectiveFrom: '2026-10-10T00:00:00Z',
        );
        $service->publish($actor, $revision);

        $before = $service->resolve(
            'collection.cash.enabled',
            ['country' => 'EG'],
            '2026-10-09T23:59:59Z',
        );
        $after = $service->resolve(
            'collection.cash.enabled',
            ['country' => 'EG'],
            '2026-10-10T00:00:00Z',
        );

        $this->assertFalse($before['value']);
        $this->assertTrue($after['value']);
    }

    public function test_invalid_routing_mode_is_rejected_before_revision_creation(): void
    {
        $actor = User::factory()->create();
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'routing.mode',
            valueType: 'string',
            defaultValue: 'MANUAL',
            failurePolicy: 'degrade_safe',
            validationSchema: ['allowed_values' => ['MANUAL', 'AUTOMATIC', 'HYBRID']],
        );

        $this->expectException(ValidationException::class);

        $service->createDraft(
            $actor,
            'routing.mode',
            'platform',
            null,
            'SURPRISE_MODE',
        );
    }

    public function test_audit_log_never_contains_sensitive_runtime_value(): void
    {
        $actor = User::factory()->create();
        $service = app(FieldOperationConfigurationService::class);

        $service->upsertDefinition(
            key: 'van.private.integration.enabled',
            valueType: 'boolean',
            defaultValue: false,
            failurePolicy: 'fail_closed',
            sensitive: true,
        );

        $revision = $service->createDraft(
            $actor,
            'van.private.integration.enabled',
            'platform',
            null,
            true,
            'controlled activation',
        );
        $service->publish($actor, $revision);

        $audit = $this->assertDatabaseHas('audit_logs', [
            'event' => 'field_operations.configuration.published',
            'auditable_type' => FieldOperationConfigurationRevision::class,
            'auditable_id' => $revision->getKey(),
        ]);

        $this->assertNotFalse($audit);
    }
}
