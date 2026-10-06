<?php

namespace App\Services;

use App\Models\FieldOperationConfiguration;
use App\Models\RoutingDecisionTrace;
use App\Models\RoutingPolicy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RoutingPolicyService
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    public function __construct(
        private readonly FieldOperationConfigurationService $configurationService,
    ) {}

    /** @param  array<int,array{name:string,conditions:array<string,mixed>,actions:array<string,mixed>,enabled?:bool}>  $rules */
    public function createDraft(User $actor, string $code, string $mode, array $rules, ?string $reason = null, mixed $from = null, mixed $until = null): RoutingPolicy
    {
        $mode = strtoupper($mode);
        $this->assertMode($mode);

        $start = $from ? Carbon::parse($from) : null;
        $end = $until ? Carbon::parse($until) : null;
        if ($start && $end && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['effective_until' => ['Must be after effective_from.']]);
        }

        return DB::transaction(function () use ($actor, $code, $mode, $rules, $reason, $start, $end): RoutingPolicy {
            $latest = RoutingPolicy::query()->where('code', $code)->lockForUpdate()->orderByDesc('version')->first();
            $policy = RoutingPolicy::query()->create([
                'public_id' => (string) Str::uuid(),
                'code' => $code,
                'version' => ((int) ($latest?->version ?? 0)) + 1,
                'status' => self::DRAFT,
                'mode' => $mode,
                'effective_from' => $start,
                'effective_until' => $end,
                'reason' => $reason,
                'previous_policy_id' => $latest?->id,
                'created_by' => $actor->id,
            ]);

            foreach (array_values($rules) as $i => $rule) {
                $policy->rules()->create([
                    'position' => $i + 1,
                    'name' => (string) $rule['name'],
                    'conditions' => $rule['conditions'] ?? [],
                    'actions' => $rule['actions'] ?? [],
                    'enabled' => $rule['enabled'] ?? true,
                ]);
            }

            return $policy->load('rules');
        });
    }

    public function publish(User $actor, RoutingPolicy $policy): RoutingPolicy
    {
        return DB::transaction(function () use ($actor, $policy): RoutingPolicy {
            $draft = RoutingPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            abort_unless($draft->status === self::DRAFT, 409, 'Only draft routing policies may be published.');

            RoutingPolicy::query()
                ->where('code', $draft->code)
                ->where('status', self::PUBLISHED)
                ->whereKeyNot($draft->getKey())
                ->lockForUpdate()
                ->update(['status' => self::RETIRED, 'updated_at' => now()]);

            $draft->forceFill([
                'status' => self::PUBLISHED,
                'published_by' => $actor->id,
                'published_at' => now(),
            ])->save();

            return $draft->fresh('rules');
        });
    }

    public function retire(RoutingPolicy $policy): RoutingPolicy
    {
        abort_unless($policy->status === self::PUBLISHED, 409);
        $policy->forceFill(['status' => self::RETIRED])->save();

        return $policy->fresh('rules');
    }

    public function rollback(User $actor, RoutingPolicy $source, ?string $reason = null): RoutingPolicy
    {
        abort_unless(in_array($source->status, [self::PUBLISHED, self::RETIRED], true), 409);
        $source->loadMissing('rules');

        $draft = $this->createDraft(
            $actor,
            $source->code,
            $source->mode,
            $source->rules->map(fn ($rule) => [
                'name' => $rule->name,
                'conditions' => $rule->conditions,
                'actions' => $rule->actions,
                'enabled' => $rule->enabled,
            ])->all(),
            $reason ?? 'rollback',
            $source->effective_from,
            $source->effective_until,
        );

        return $this->publish($actor, $draft);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,string|int>  $scope
     * @return array<string,mixed>
     */
    public function simulate(RoutingPolicy $policy, array $input, array $scope = [], mixed $at = null): array
    {
        $policy->loadMissing('rules');
        $moment = $at ? Carbon::parse($at) : now();

        return $this->evaluate($policy, $input, $this->resolveMode($policy, $scope, $moment));
    }

    /**
     * @param  array<int,array<string,mixed>>  $inputs
     * @param  array<string,string|int>  $scope
     * @return array<int,array<string,mixed>>
     */
    public function simulateBatch(RoutingPolicy $policy, array $inputs, array $scope = [], mixed $at = null): array
    {
        return array_map(
            fn (array $input): array => $this->simulate($policy, $input, $scope, $at),
            array_values($inputs),
        );
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,string|int>  $scope
     */
    public function route(string $code, string $subjectType, string $subjectKey, array $input, mixed $at = null, array $scope = []): RoutingDecisionTrace
    {
        $moment = $at ? Carbon::parse($at) : now();
        $policy = RoutingPolicy::query()
            ->with('rules')
            ->where('code', $code)
            ->where('status', self::PUBLISHED)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->orderByDesc('version')
            ->firstOrFail();

        $mode = $this->resolveMode($policy, $scope, $moment);
        $decision = $this->evaluate($policy, $input, $mode);

        return RoutingDecisionTrace::query()->create([
            'public_id' => (string) Str::uuid(),
            'routing_policy_id' => $policy->id,
            'subject_type' => $subjectType,
            'subject_key' => $subjectKey,
            'input_snapshot' => $input,
            'result' => $decision['result'],
            'evaluated_rules' => $decision['evaluated_rules'],
            'routing_mode' => $decision['routing_mode'],
            'mode_resolution' => $decision['mode_resolution'],
            'decided_at' => $moment,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $mode
     * @return array<string,mixed>
     */
    private function evaluate(RoutingPolicy $policy, array $input, array $mode): array
    {
        $result = [];
        $evaluated = [];

        foreach ($policy->rules->where('enabled', true)->sortBy('position') as $rule) {
            $rejected = [];
            $matched = true;

            foreach ((array) $rule->conditions as $key => $expected) {
                $actual = data_get($input, $key);
                if ($actual !== $expected) {
                    $matched = false;
                    $rejected[] = [
                        'condition' => $key,
                        'expected' => $expected,
                        'actual' => $actual,
                    ];
                }
            }

            if ($matched) {
                foreach ((array) $rule->actions as $key => $value) {
                    $result[$key] = $value;
                }
            }

            $evaluated[] = [
                'rule_id' => $rule->id,
                'position' => $rule->position,
                'name' => $rule->name,
                'matched' => $matched,
                'rejected' => $rejected,
            ];
        }

        return [
            'policy' => [
                'id' => $policy->public_id,
                'code' => $policy->code,
                'version' => $policy->version,
            ],
            'routing_mode' => $mode['value'],
            'mode_resolution' => $mode,
            'result' => $result,
            'evaluated_rules' => $evaluated,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string,string|int>  $scope
     * @return array<string,mixed>
     */
    private function resolveMode(RoutingPolicy $policy, array $scope, Carbon $moment): array
    {
        if (FieldOperationConfiguration::query()->where('key', 'routing.mode')->exists()) {
            $resolved = $this->configurationService->resolve('routing.mode', $scope, $moment);
            $mode = strtoupper((string) $resolved['value']);
            $this->assertMode($mode);
            $resolved['value'] = $mode;

            return $resolved;
        }

        return [
            'key' => 'routing.mode',
            'value' => strtoupper((string) $policy->mode),
            'source' => 'policy_fallback',
            'scope_type' => null,
            'scope_key' => null,
            'revision' => null,
        ];
    }

    private function assertMode(string $mode): void
    {
        if (in_array($mode, ['MANUAL', 'AUTOMATIC', 'HYBRID'], true) === false) {
            throw ValidationException::withMessages(['mode' => ['Unsupported routing mode.']]);
        }
    }
}
