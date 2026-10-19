<?php

namespace App\Domain\Analytics\Signals;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Validation\ValidationException;

final class SignalLifecycleManager
{
    private const TRANSITIONS = [
        'new' => ['acknowledged', 'resolved', 'suppressed'],
        'acknowledged' => ['resolved', 'suppressed'],
        'suppressed' => ['new', 'resolved'],
        'resolved' => ['new'],
    ];

    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $signal
     * @return array<string,mixed>
     */
    public function transition(
        User $actor,
        array $signal,
        string $toState,
        ?string $reason = null,
        ?string $occurredAt = null,
    ): array {
        $this->assertAuthorized($actor, $signal);

        $fromState = strtolower(trim((string) ($signal['lifecycle']['state'] ?? '')));
        $toState = strtolower(trim($toState));
        $allowed = self::TRANSITIONS[$fromState] ?? [];

        if (! in_array($toState, $allowed, true)) {
            throw ValidationException::withMessages([
                'state' => ["Signal lifecycle transition [{$fromState} -> {$toState}] is not allowed."],
            ]);
        }

        $reason = $reason === null ? null : trim($reason);
        if ($toState === 'suppressed' && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages([
                'reason' => ['Suppressing a signal requires a reason.'],
            ]);
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => ['Signal lifecycle reason must not exceed 500 characters.'],
            ]);
        }

        $occurredAt ??= now()->toIso8601String();
        $fingerprint = trim((string) ($signal['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            throw ValidationException::withMessages([
                'fingerprint' => ['Signal lifecycle actions require a deterministic fingerprint.'],
            ]);
        }

        $before = [
            'store_id' => $this->firstStoreId($signal),
            'fingerprint' => $fingerprint,
            'state' => $fromState,
            'severity' => $signal['severity'] ?? null,
            'code' => $signal['code'] ?? null,
        ];

        $signal['lifecycle'] = [
            'state' => $toState,
            'allowed_next' => self::TRANSITIONS[$toState],
            'changed_at' => $occurredAt,
            'changed_by_user_id' => (int) $actor->getKey(),
            'reason' => $reason,
        ];

        $event = [
            'from' => $fromState,
            'to' => $toState,
            'at' => $occurredAt,
            'actor_user_id' => (int) $actor->getKey(),
            'reason' => $reason,
        ];
        $trail = is_array($signal['audit_trail'] ?? null) ? $signal['audit_trail'] : [];
        $trail[] = $event;
        $signal['audit_trail'] = $trail;

        $after = [
            'store_id' => $this->firstStoreId($signal),
            'fingerprint' => $fingerprint,
            'state' => $toState,
            'severity' => $signal['severity'] ?? null,
            'code' => $signal['code'] ?? null,
            'reason' => $reason,
        ];

        $this->audit->record(
            'assistant.signal.lifecycle',
            $actor,
            null,
            $before,
            $after,
        );

        return $signal;
    }

    /** @param array<string,mixed> $signal */
    private function assertAuthorized(User $actor, array $signal): void
    {
        abort_unless((bool) $actor->is_active, 403);

        $channel = strtolower(trim((string) ($signal['scope']['channel'] ?? '')));
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422);

        $storeIds = $this->storeIds($signal);
        abort_if($storeIds === [], 422, 'Signal scope must contain at least one authorized store id.');

        $assistantStores = $this->scope->allowedStoreIds($actor, 'assistant.use', $channel);
        $reportStores = $this->scope->allowedStoreIds($actor, 'reports.view', $channel);
        $allowed = array_values(array_intersect($assistantStores, $reportStores));

        foreach ($storeIds as $storeId) {
            abort_unless(in_array($storeId, $allowed, true), 404);
        }
    }

    /** @param array<string,mixed> $signal @return list<int> */
    private function storeIds(array $signal): array
    {
        $ids = [];
        foreach ((array) ($signal['scope']['store_ids'] ?? []) as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT);
            if (is_int($value) && $value > 0) {
                $ids[$value] = true;
            }
        }
        $storeIds = array_keys($ids);
        sort($storeIds);

        return $storeIds;
    }

    /** @param array<string,mixed> $signal */
    private function firstStoreId(array $signal): ?int
    {
        return $this->storeIds($signal)[0] ?? null;
    }
}
