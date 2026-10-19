<?php

namespace App\Domain\Assistant\Intelligence;

use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceBrief;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AssistantBriefDispatcher
{
    public function __construct(
        private AssistantIntelligenceAuthorization $authorization,
    ) {}

    public function canAccess(User $user, AssistantIntelligenceBrief $brief): bool
    {
        return $this->authorization->canUse(
            $user,
            $brief->store_id === null ? null : (int) $brief->store_id,
            $brief->channel,
        ) && $this->itemsStillAuthorized($user, $brief->payload);
    }

    public function deliver(AssistantIntelligenceBrief $brief): bool
    {
        if ($brief->state === AssistantIntelligenceBrief::STATE_DELIVERED) {
            return true;
        }

        $user = User::query()
            ->whereKey($brief->user_id)
            ->where('is_active', true)
            ->first();

        $authorized = $user instanceof User && $this->canAccess($user, $brief);

        if (! $authorized) {
            $brief->forceFill([
                'state' => AssistantIntelligenceBrief::STATE_BLOCKED,
                'authorization_checked_at' => now(),
            ])->save();

            return false;
        }

        return DB::transaction(function () use ($brief, $user): bool {
            $locked = AssistantIntelligenceBrief::query()
                ->whereKey($brief->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state === AssistantIntelligenceBrief::STATE_DELIVERED) {
                return true;
            }

            if (! $this->canAccess($user, $locked)) {
                $locked->forceFill([
                    'state' => AssistantIntelligenceBrief::STATE_BLOCKED,
                    'authorization_checked_at' => now(),
                ])->save();

                return false;
            }

            $payload = $locked->payload;
            $count = (int) ($payload['finding_count'] ?? 0);
            $cadenceAr = $locked->cadence === 'weekly' ? 'الأسبوعي' : 'اليومي';
            $cadenceEn = $locked->cadence === 'weekly' ? 'weekly' : 'daily';
            $snapshot = $locked->snapshot_at->setTimezone('Asia/Kuwait')->format('Y-m-d H:i');

            $notification = Notification::query()->firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'dedupe_key' => 'assistant-brief:'.$locked->idempotency_key,
                ],
                [
                    'channel' => 'in_app',
                    'type' => 'assistant.intelligence_brief',
                    'title' => 'ملخص FOODEX الذكي '.$cadenceAr,
                    'body' => "لديك {$count} نتيجة قابلة للمراجعة. لقطة البيانات: {$snapshot}.",
                    'title_ar' => 'ملخص FOODEX الذكي '.$cadenceAr,
                    'title_en' => 'FOODEX '.$cadenceEn.' intelligence brief',
                    'body_ar' => "لديك {$count} نتيجة قابلة للمراجعة. لقطة البيانات: {$snapshot}.",
                    'body_en' => "You have {$count} reviewable intelligence finding(s). Snapshot: {$snapshot}.",
                    'audience' => 'user',
                    'app' => 'all',
                    'target_channel' => 'all',
                    'store_id' => $locked->store_id,
                    'status' => 'published',
                    'published_at' => now(),
                    'data' => [
                        'brief_id' => (int) $locked->getKey(),
                        'cadence' => $locked->cadence,
                        'snapshot_at' => $locked->snapshot_at->toIso8601String(),
                        'deep_link' => route('admin.assistant-intelligence.index', [], false),
                        'promotional' => false,
                    ],
                ],
            );

            $locked->forceFill([
                'state' => AssistantIntelligenceBrief::STATE_DELIVERED,
                'notification_id' => $notification->getKey(),
                'authorization_checked_at' => now(),
                'delivered_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    private function itemsStillAuthorized(User $user, array $payload): bool
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            return true;
        }

        $ids = collect($items)
            ->pluck('finding_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $findings = AssistantIntelligenceFinding::query()
            ->whereIn('id', $ids)
            ->where('recipient_user_id', $user->getKey())
            ->get()
            ->keyBy('id');

        if ($findings->count() !== count($ids)) {
            return false;
        }

        foreach ($findings as $finding) {
            if (! $this->authorization->canUseFinding($user, $finding)) {
                return false;
            }
        }

        return true;
    }
}
