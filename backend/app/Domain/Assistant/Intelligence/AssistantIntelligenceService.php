<?php

namespace App\Domain\Assistant\Intelligence;

use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AssistantIntelligenceService
{
    public const SEVERITIES = ['info', 'opportunity', 'warning', 'critical'];

    public function __construct(
        private AssistantIntelligenceAuthorization $authorization,
    ) {}

    /**
     * Stable ingestion seam for deterministic #2219/#2220/#2222 findings.
     *
     * @param array{
     *   source_key:string,dedupe_key:string,severity:string,title_ar:string,title_en:string,
     *   narrative_ar:string,narrative_en:string,evidence:array<string,mixed>,
     *   proposed_action?:array<string,mixed>|null,snapshot_at:string|\DateTimeInterface,
     *   stale_after_at?:string|\DateTimeInterface|null,store_id?:int|null,channel?:string|null
     * } $finding
     */
    public function upsertForUser(User $recipient, array $finding): AssistantIntelligenceFinding
    {
        $storeId = isset($finding['store_id']) ? (int) $finding['store_id'] : null;
        $storeId = $storeId !== null && $storeId > 0 ? $storeId : null;
        $channel = $this->channel($finding['channel'] ?? null);
        $severity = strtolower(trim($finding['severity']));

        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unsupported intelligence finding severity.');
        }

        $this->authorization->assertCanUseFindingPayload(
            $recipient,
            $storeId,
            $channel,
            $finding['evidence'],
        );

        $sourceKey = trim($finding['source_key']);
        $dedupeKey = trim($finding['dedupe_key']);
        if ($sourceKey === '' || $dedupeKey === '') {
            throw new InvalidArgumentException('Intelligence findings require source_key and dedupe_key.');
        }

        return DB::transaction(function () use (
            $recipient,
            $finding,
            $storeId,
            $channel,
            $severity,
            $sourceKey,
            $dedupeKey,
        ): AssistantIntelligenceFinding {
            $existing = AssistantIntelligenceFinding::query()
                ->where('recipient_user_id', $recipient->getKey())
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            $status = $existing instanceof AssistantIntelligenceFinding
                ? $existing->status
                : AssistantIntelligenceFinding::STATUS_UNREAD;

            return AssistantIntelligenceFinding::query()->updateOrCreate(
                [
                    'recipient_user_id' => $recipient->getKey(),
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'store_id' => $storeId,
                    'channel' => $channel,
                    'source_key' => $sourceKey,
                    'severity' => $severity,
                    'status' => $status,
                    'title_ar' => trim((string) $finding['title_ar']),
                    'title_en' => trim((string) $finding['title_en']),
                    'narrative_ar' => trim((string) $finding['narrative_ar']),
                    'narrative_en' => trim((string) $finding['narrative_en']),
                    'evidence' => $finding['evidence'],
                    'proposed_action' => is_array($finding['proposed_action'] ?? null)
                        ? $finding['proposed_action']
                        : null,
                    'snapshot_at' => CarbonImmutable::parse($finding['snapshot_at'])->utc(),
                    'stale_after_at' => ($finding['stale_after_at'] ?? null) === null
                        ? null
                        : CarbonImmutable::parse($finding['stale_after_at'])->utc(),
                ],
            );
        }, 3);
    }

    /** @return Collection<int,AssistantIntelligenceFinding> */
    public function visibleFindings(User $actor, int $limit = 100): Collection
    {
        return AssistantIntelligenceFinding::query()
            ->where('recipient_user_id', $actor->getKey())
            ->latest('snapshot_at')
            ->latest('id')
            ->limit(max(1, min($limit, 200)))
            ->get()
            ->filter(fn (AssistantIntelligenceFinding $finding): bool => $this->authorization->canUseFinding(
                $actor,
                $finding,
            ))
            ->values();
    }

    public function transition(
        User $actor,
        int $findingId,
        string $status,
    ): AssistantIntelligenceFinding {
        if (! in_array($status, [
            AssistantIntelligenceFinding::STATUS_ACKNOWLEDGED,
            AssistantIntelligenceFinding::STATUS_RESOLVED,
        ], true)) {
            throw new InvalidArgumentException('Unsupported intelligence finding status.');
        }

        return DB::transaction(function () use ($actor, $findingId, $status): AssistantIntelligenceFinding {
            $finding = AssistantIntelligenceFinding::query()
                ->whereKey($findingId)
                ->where('recipient_user_id', $actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($this->authorization->canUseFinding($actor, $finding), 403);

            $finding->forceFill([
                'status' => $status,
                'acknowledged_at' => $status === AssistantIntelligenceFinding::STATUS_ACKNOWLEDGED
                    ? ($finding->acknowledged_at ?? now())
                    : $finding->acknowledged_at,
                'resolved_at' => $status === AssistantIntelligenceFinding::STATUS_RESOLVED
                    ? now()
                    : null,
            ])->save();

            return $finding->refresh();
        }, 3);
    }

    public function globalSubscription(User $user): AssistantIntelligenceSubscription
    {
        $this->authorization->assertCanUse($user);

        return AssistantIntelligenceSubscription::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'scope_key' => 'global'],
            [
                'store_id' => null,
                'channel' => null,
                'enabled' => true,
                'daily_enabled' => true,
                'weekly_enabled' => true,
                'minimum_severity' => 'info',
                'delivery_channel' => 'in_app',
                'locale' => in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar',
            ],
        );
    }

    /** @param array{enabled:bool,daily_enabled:bool,weekly_enabled:bool,minimum_severity:string,locale:string} $values */
    public function updateGlobalSubscription(User $user, array $values): AssistantIntelligenceSubscription
    {
        $subscription = $this->globalSubscription($user);
        $severity = strtolower(trim($values['minimum_severity']));

        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unsupported intelligence subscription severity.');
        }

        $subscription->forceFill([
            'enabled' => $values['enabled'],
            'daily_enabled' => $values['daily_enabled'],
            'weekly_enabled' => $values['weekly_enabled'],
            'minimum_severity' => $severity,
            'delivery_channel' => 'in_app',
            'locale' => in_array($values['locale'], ['ar', 'en'], true) ? $values['locale'] : 'ar',
        ])->save();

        return $subscription->refresh();
    }

    private function channel(mixed $channel): ?string
    {
        if (! is_string($channel) || trim($channel) === '') {
            return null;
        }

        $channel = strtolower(trim($channel));

        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new InvalidArgumentException('Unsupported intelligence finding channel.');
        }

        return $channel;
    }
}
