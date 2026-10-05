<?php

namespace App\Services;

use App\Models\FieldOperationConfiguration;
use App\Models\FieldOperationConfigurationRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FieldOperationConfigurationService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * More-specific scopes win. These are platform dimensions, not business
     * values; actual scope identifiers remain data-driven.
     *
     * @var array<string,int>
     */
    private const SCOPE_PRIORITY = [
        'platform' => 0,
        'country' => 10,
        'region' => 20,
        'governorate' => 30,
        'territory' => 40,
        'warehouse' => 50,
        'store' => 60,
        'shift' => 70,
        'van_pool' => 80,
        'van' => 90,
        'customer' => 100,
        'address' => 110,
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Register or update definition metadata. Runtime values are never changed
     * here; they remain immutable, scoped revisions.
     *
     * @param array<string,mixed> $validationSchema
     * @param array<int,array<string,mixed>|string> $dependencies
     */
    public function upsertDefinition(
        string $key,
        string $valueType,
        mixed $defaultValue,
        string $failurePolicy,
        array $validationSchema = [],
        array $dependencies = [],
        bool $sensitive = false,
        ?string $description = null,
    ): FieldOperationConfiguration {
        $this->assertKey($key);
        $this->assertValueType($valueType);
        $this->assertFailurePolicy($failurePolicy);
        $this->validateValue($valueType, $defaultValue, $validationSchema);

        return FieldOperationConfiguration::query()->updateOrCreate(
            ['key' => $key],
            [
                'value_type' => $valueType,
                'validation_schema' => $validationSchema,
                'default_value' => ['value' => $defaultValue],
                'failure_policy' => $failurePolicy,
                'dependencies' => $dependencies,
                'is_sensitive' => $sensitive,
                'description' => $description,
            ],
        );
    }

    public function createDraft(
        User $actor,
        string $key,
        string $scopeType,
        ?string $scopeKey,
        mixed $value,
        ?string $reason = null,
        Carbon|string|null $effectiveFrom = null,
        Carbon|string|null $effectiveUntil = null,
        ?Request $request = null,
    ): FieldOperationConfigurationRevision {
        $definition = FieldOperationConfiguration::query()->where('key', $key)->firstOrFail();
        $scopeKey = $this->normalizeScope($scopeType, $scopeKey);
        $schema = (array) ($definition->validation_schema ?? []);

        $this->validateValue((string) $definition->value_type, $value, $schema);
        [$from, $until] = $this->normalizeWindow($effectiveFrom, $effectiveUntil);

        return DB::transaction(function () use (
            $actor,
            $definition,
            $scopeType,
            $scopeKey,
            $value,
            $reason,
            $from,
            $until,
            $request,
        ): FieldOperationConfigurationRevision {
            $latest = FieldOperationConfigurationRevision::query()
                ->where('configuration_id', $definition->getKey())
                ->where('scope_type', $scopeType)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->latest('revision_number')
                ->first();

            $revision = FieldOperationConfigurationRevision::query()->create([
                'public_id' => (string) Str::uuid(),
                'configuration_id' => $definition->getKey(),
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'revision_number' => ((int) ($latest?->revision_number ?? 0)) + 1,
                'status' => self::STATUS_DRAFT,
                'value' => ['value' => $value],
                'reason' => $reason,
                'effective_from' => $from,
                'effective_until' => $until,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                'field_operations.configuration.draft_created',
                $actor,
                $revision,
                null,
                $this->auditPayload($revision),
                $request,
            );

            return $revision;
        });
    }

    public function publish(
        User $actor,
        FieldOperationConfigurationRevision $revision,
        ?Request $request = null,
    ): FieldOperationConfigurationRevision {
        return DB::transaction(function () use ($actor, $revision, $request): FieldOperationConfigurationRevision {
            $draft = FieldOperationConfigurationRevision::query()
                ->with('configuration')
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($draft->status === self::STATUS_DRAFT, 409, 'Only draft configuration revisions can be published.');
            $this->validateRevision($draft);

            $current = FieldOperationConfigurationRevision::query()
                ->where('configuration_id', $draft->configuration_id)
                ->where('scope_type', $draft->scope_type)
                ->where('scope_key', $draft->scope_key)
                ->where('status', self::STATUS_PUBLISHED)
                ->whereKeyNot($draft->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($current as $published) {
                $published->forceFill(['status' => self::STATUS_ARCHIVED])->save();
            }

            $draft->forceFill([
                'status' => self::STATUS_PUBLISHED,
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ])->save();

            $this->audit->record(
                'field_operations.configuration.published',
                $actor,
                $draft,
                $current->isEmpty() ? null : $this->auditPayload($current->last()),
                $this->auditPayload($draft),
                $request,
            );

            return $draft->fresh(['configuration']);
        });
    }

    public function rollback(
        User $actor,
        FieldOperationConfigurationRevision $source,
        ?string $reason = null,
        ?Request $request = null,
    ): FieldOperationConfigurationRevision {
        abort_unless(
            in_array($source->status, [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true),
            409,
            'Only published or archived revisions can be rollback sources.',
        );

        $source->loadMissing('configuration');
        $this->validateRevision($source);

        return DB::transaction(function () use ($actor, $source, $reason, $request): FieldOperationConfigurationRevision {
            $latest = FieldOperationConfigurationRevision::query()
                ->where('configuration_id', $source->configuration_id)
                ->where('scope_type', $source->scope_type)
                ->where('scope_key', $source->scope_key)
                ->lockForUpdate()
                ->latest('revision_number')
                ->first();

            FieldOperationConfigurationRevision::query()
                ->where('configuration_id', $source->configuration_id)
                ->where('scope_type', $source->scope_type)
                ->where('scope_key', $source->scope_key)
                ->where('status', self::STATUS_PUBLISHED)
                ->update(['status' => self::STATUS_ARCHIVED, 'updated_at' => now()]);

            $restored = FieldOperationConfigurationRevision::query()->create([
                'public_id' => (string) Str::uuid(),
                'configuration_id' => $source->configuration_id,
                'scope_type' => $source->scope_type,
                'scope_key' => $source->scope_key,
                'revision_number' => ((int) ($latest?->revision_number ?? 0)) + 1,
                'status' => self::STATUS_PUBLISHED,
                'value' => $source->value,
                'reason' => $reason ?? 'rollback',
                'effective_from' => $source->effective_from,
                'effective_until' => $source->effective_until,
                'source_revision_id' => $source->getKey(),
                'created_by' => $actor->getKey(),
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ]);

            $this->audit->record(
                'field_operations.configuration.rolled_back',
                $actor,
                $restored,
                $latest instanceof FieldOperationConfigurationRevision ? $this->auditPayload($latest) : null,
                $this->auditPayload($restored),
                $request,
            );

            return $restored->fresh(['configuration']);
        });
    }

    /**
     * @param array<string,string|int> $scope
     * @return array<string,mixed>
     */
    public function resolve(string $key, array $scope = [], Carbon|string|null $at = null): array
    {
        $definition = FieldOperationConfiguration::query()->where('key', $key)->firstOrFail();
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));

        $candidates = FieldOperationConfigurationRevision::query()
            ->where('configuration_id', $definition->getKey())
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function ($query) use ($moment): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $moment);
            })
            ->where(function ($query) use ($moment): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment);
            })
            ->latest('revision_number')
            ->get();

        $requested = ['platform' => '*'];
        foreach ($scope as $type => $value) {
            $this->assertScopeType((string) $type);
            $requested[(string) $type] = (string) $value;
        }

        $winner = $candidates
            ->filter(function (FieldOperationConfigurationRevision $revision) use ($requested): bool {
                return array_key_exists((string) $revision->scope_type, $requested)
                    && $requested[(string) $revision->scope_type] === (string) $revision->scope_key;
            })
            ->sortByDesc(function (FieldOperationConfigurationRevision $revision): int {
                return self::SCOPE_PRIORITY[(string) $revision->scope_type] ?? -1;
            })
            ->first();

        if (($winner instanceof FieldOperationConfigurationRevision) === false) {
            return [
                'key' => (string) $definition->key,
                'value' => data_get($definition->default_value, 'value'),
                'source' => 'default',
                'scope_type' => 'platform',
                'scope_key' => '*',
                'revision' => null,
                'failure_policy' => (string) $definition->failure_policy,
                'dependencies' => (array) ($definition->dependencies ?? []),
            ];
        }

        return [
            'key' => (string) $definition->key,
            'value' => data_get($winner->value, 'value'),
            'source' => 'published_revision',
            'scope_type' => (string) $winner->scope_type,
            'scope_key' => (string) $winner->scope_key,
            'revision' => (int) $winner->revision_number,
            'revision_id' => (string) $winner->public_id,
            'effective_from' => $winner->effective_from?->toIso8601String(),
            'effective_until' => $winner->effective_until?->toIso8601String(),
            'failure_policy' => (string) $definition->failure_policy,
            'dependencies' => (array) ($definition->dependencies ?? []),
        ];
    }

    private function validateRevision(FieldOperationConfigurationRevision $revision): void
    {
        $definition = $revision->configuration;
        abort_unless($definition instanceof FieldOperationConfiguration, 500);

        $this->validateValue(
            (string) $definition->value_type,
            data_get($revision->value, 'value'),
            (array) ($definition->validation_schema ?? []),
        );
    }

    /** @param array<string,mixed> $schema */
    private function validateValue(string $type, mixed $value, array $schema): void
    {
        $validType = match ($type) {
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'object' => is_array($value) && array_is_list($value) === false,
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };

        if ($validType === false) {
            throw ValidationException::withMessages([
                'value' => ["Configuration value must match type {$type}."],
            ]);
        }

        $allowed = $schema['allowed_values'] ?? null;
        if (is_array($allowed) && in_array($value, $allowed, true) === false) {
            throw ValidationException::withMessages([
                'value' => ['Configuration value is not in the allowed set.'],
            ]);
        }

        if (is_numeric($value) && isset($schema['min']) && $value < $schema['min']) {
            throw ValidationException::withMessages(['value' => ['Configuration value is below the allowed minimum.']]);
        }
        if (is_numeric($value) && isset($schema['max']) && $value > $schema['max']) {
            throw ValidationException::withMessages(['value' => ['Configuration value exceeds the allowed maximum.']]);
        }
    }

    private function normalizeScope(string $scopeType, ?string $scopeKey): string
    {
        $this->assertScopeType($scopeType);

        if ($scopeType === 'platform') {
            return '*';
        }

        $normalized = trim((string) $scopeKey);
        if ($normalized === '') {
            throw ValidationException::withMessages(['scope_key' => ['A non-platform scope requires a scope key.']]);
        }

        return $normalized;
    }

    private function assertScopeType(string $scopeType): void
    {
        if (array_key_exists($scopeType, self::SCOPE_PRIORITY) === false) {
            throw ValidationException::withMessages(['scope_type' => ['Unsupported configuration scope.']]);
        }
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,127}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => ['Configuration keys must be stable lowercase codes.']]);
        }
    }

    private function assertValueType(string $type): void
    {
        if (in_array($type, ['boolean', 'string', 'integer', 'number', 'object', 'array'], true) === false) {
            throw ValidationException::withMessages(['value_type' => ['Unsupported configuration value type.']]);
        }
    }

    private function assertFailurePolicy(string $policy): void
    {
        if (in_array($policy, ['fail_closed', 'degrade_safe'], true) === false) {
            throw ValidationException::withMessages(['failure_policy' => ['Failure policy must be fail_closed or degrade_safe.']]);
        }
    }

    /** @return array{0:?Carbon,1:?Carbon} */
    private function normalizeWindow(Carbon|string|null $from, Carbon|string|null $until): array
    {
        $start = $from instanceof Carbon ? $from : ($from === null ? null : Carbon::parse($from));
        $end = $until instanceof Carbon ? $until : ($until === null ? null : Carbon::parse($until));

        if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'effective_until' => ['Effective-until must be after effective-from.'],
            ]);
        }

        return [$start, $end];
    }

    /** @return array<string,mixed> */
    private function auditPayload(FieldOperationConfigurationRevision $revision): array
    {
        return [
            'configuration_id' => (int) $revision->configuration_id,
            'revision_id' => (string) $revision->public_id,
            'scope_type' => (string) $revision->scope_type,
            'scope_key' => (string) $revision->scope_key,
            'revision_number' => (int) $revision->revision_number,
            'status' => (string) $revision->status,
            'effective_from' => $revision->effective_from?->toIso8601String(),
            'effective_until' => $revision->effective_until?->toIso8601String(),
            'source_revision_id' => $revision->source_revision_id,
        ];
    }
}
