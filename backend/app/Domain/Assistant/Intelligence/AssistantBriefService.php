<?php

namespace App\Domain\Assistant\Intelligence;

use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceBrief;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AssistantBriefService
{
    private const SEVERITY_RANK = [
        'info' => 0,
        'opportunity' => 1,
        'warning' => 2,
        'critical' => 3,
    ];

    public function __construct(
        private AssistantIntelligenceAuthorization $authorization,
        private AssistantBriefDispatcher $dispatcher,
    ) {}

    public function generate(
        AssistantIntelligenceSubscription $subscription,
        string $cadence,
        ?CarbonImmutable $asOf = null,
    ): ?AssistantIntelligenceBrief {
        if (! in_array($cadence, ['daily', 'weekly'], true)) {
            throw new InvalidArgumentException('Unsupported intelligence brief cadence.');
        }

        if (! $subscription->enabled
            || ($cadence === 'daily' && ! $subscription->daily_enabled)
            || ($cadence === 'weekly' && ! $subscription->weekly_enabled)) {
            return null;
        }

        $recipient = User::query()
            ->whereKey($subscription->user_id)
            ->where('is_active', true)
            ->first();

        if (! $recipient instanceof User || ! $this->authorization->canUse(
            $recipient,
            $subscription->store_id === null ? null : (int) $subscription->store_id,
            $subscription->channel,
        )) {
            return null;
        }

        $asOf ??= CarbonImmutable::now('Asia/Kuwait');
        [$periodStart, $periodEnd] = $this->period($cadence, $asOf);
        $minimumRank = self::SEVERITY_RANK[$subscription->minimum_severity] ?? 0;
        $locale = in_array($subscription->locale, ['ar', 'en'], true)
            ? $subscription->locale
            : (in_array($recipient->locale, ['ar', 'en'], true) ? $recipient->locale : 'ar');

        $findings = AssistantIntelligenceFinding::query()
            ->where('recipient_user_id', $recipient->getKey())
            ->where('status', '!=', AssistantIntelligenceFinding::STATUS_RESOLVED)
            ->whereBetween('snapshot_at', [$periodStart->utc(), $asOf->utc()])
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('stale_after_at')
                    ->orWhere('stale_after_at', '>=', $asOf->utc());
            })
            ->when(
                $subscription->store_id !== null,
                fn ($query) => $query->where('store_id', $subscription->store_id),
            )
            ->when(
                $subscription->channel !== null,
                fn ($query) => $query->where('channel', $subscription->channel),
            )
            ->latest('snapshot_at')
            ->limit(200)
            ->get()
            ->filter(fn (AssistantIntelligenceFinding $finding): bool => (self::SEVERITY_RANK[$finding->severity] ?? 0) >= $minimumRank)
            ->filter(fn (AssistantIntelligenceFinding $finding): bool => $this->authorization->canUseFinding(
                $recipient,
                $finding,
            ))
            ->values();

        $idempotencyKey = hash('sha256', implode('|', [
            'assistant-intelligence-brief',
            $recipient->getKey(),
            $subscription->scope_key,
            $cadence,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            $locale,
        ]));

        return DB::transaction(function () use (
            $subscription,
            $recipient,
            $cadence,
            $periodStart,
            $periodEnd,
            $asOf,
            $locale,
            $findings,
            $idempotencyKey,
        ): AssistantIntelligenceBrief {
            $existing = AssistantIntelligenceBrief::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof AssistantIntelligenceBrief) {
                return $existing;
            }

            $items = $findings->map(function (AssistantIntelligenceFinding $finding) use ($locale): array {
                return [
                    'finding_id' => (int) $finding->getKey(),
                    'source_key' => $finding->source_key,
                    'severity' => $finding->severity,
                    'status' => $finding->status,
                    'title' => $locale === 'en' ? $finding->title_en : $finding->title_ar,
                    'narrative' => $locale === 'en' ? $finding->narrative_en : $finding->narrative_ar,
                    'evidence' => (array) $finding->evidence,
                    'proposed_action' => $finding->proposed_action,
                    'store_id' => $finding->store_id === null ? null : (int) $finding->store_id,
                    'channel' => $finding->channel,
                    'snapshot_at' => $finding->snapshot_at->toIso8601String(),
                    'stale_after_at' => $finding->stale_after_at?->toIso8601String(),
                ];
            })->all();

            return AssistantIntelligenceBrief::query()->create([
                'user_id' => $recipient->getKey(),
                'scope_key' => $subscription->scope_key,
                'store_id' => $subscription->store_id,
                'channel' => $subscription->channel,
                'cadence' => $cadence,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'snapshot_at' => $asOf->utc(),
                'locale' => $locale,
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'schema_version' => '1.0',
                    'cadence' => $cadence,
                    'locale' => $locale,
                    'period' => [
                        'from' => $periodStart->toIso8601String(),
                        'to' => $periodEnd->toIso8601String(),
                    ],
                    'snapshot_at' => $asOf->toIso8601String(),
                    'finding_count' => count($items),
                    'items' => $items,
                    'provider_required' => false,
                    'numbers_authority' => 'deterministic_software',
                ],
                'state' => AssistantIntelligenceBrief::STATE_GENERATED,
            ]);
        }, 3);
    }

    public function runCadence(string $cadence, int $limit = 200): int
    {
        if (! in_array($cadence, ['daily', 'weekly'], true)) {
            throw new InvalidArgumentException('Unsupported intelligence brief cadence.');
        }

        $flag = $cadence === 'daily' ? 'daily_enabled' : 'weekly_enabled';
        $subscriptions = AssistantIntelligenceSubscription::query()
            ->where('enabled', true)
            ->where($flag, true)
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $delivered = 0;
        foreach ($subscriptions as $subscription) {
            $brief = $this->generate($subscription, $cadence);
            if (! $brief instanceof AssistantIntelligenceBrief
                || $brief->state === AssistantIntelligenceBrief::STATE_DELIVERED) {
                continue;
            }

            if ($this->dispatcher->deliver($brief)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function period(string $cadence, CarbonImmutable $asOf): array
    {
        $start = $cadence === 'daily'
            ? $asOf->subDay()->startOfDay()
            : $asOf->subWeek()->startOfDay();

        return [$start, $asOf];
    }
}
