<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotificationCampaignDispatcher
{
    public function __construct(private readonly PushDeliveryService $push) {}

    public function dispatchDue(int $limit = 100): int
    {
        $ids = NotificationCampaign::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $dispatched = 0;

        foreach ($ids as $campaignId) {
            if ($this->dispatchCampaign($campaignId)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function dispatchCampaign(int $campaignId): bool
    {
        $result = null;

        try {
            $result = DB::transaction(function () use ($campaignId): ?array {
                $campaign = NotificationCampaign::query()
                    ->whereKey($campaignId)
                    ->lockForUpdate()
                    ->first();

                if (! $campaign instanceof NotificationCampaign
                    || $campaign->status !== 'active'
                    || $campaign->next_run_at === null
                    || $campaign->next_run_at->isFuture()) {
                    return null;
                }

                $scheduledFor = CarbonImmutable::instance($campaign->next_run_at);

                if ($campaign->ends_at !== null && now()->greaterThan($campaign->ends_at)) {
                    $campaign->update(['status' => 'completed', 'next_run_at' => null]);

                    return null;
                }

                $run = NotificationCampaignRun::query()->firstOrCreate(
                    [
                        'campaign_id' => $campaign->getKey(),
                        'scheduled_for' => $scheduledFor,
                    ],
                    [
                        'status' => 'running',
                        'started_at' => now(),
                    ],
                );

                if (! $run->wasRecentlyCreated) {
                    return null;
                }

                $notification = Notification::query()->create([
                    'channel' => $campaign->delivery_channel,
                    'type' => $campaign->type,
                    'title' => $campaign->title_ar,
                    'body' => $campaign->body_ar,
                    'title_ar' => $campaign->title_ar,
                    'title_en' => $campaign->title_en,
                    'body_ar' => $campaign->body_ar,
                    'body_en' => $campaign->body_en,
                    'audience' => $campaign->audience,
                    'app' => $campaign->app,
                    'target_channel' => $campaign->target_channel,
                    'user_id' => $campaign->audience === 'user' ? $campaign->user_id : null,
                    'store_id' => $campaign->store_id,
                    'created_by' => $campaign->created_by,
                    'status' => 'published',
                    'published_at' => now(),
                    'data' => [
                        'campaign_id' => (int) $campaign->getKey(),
                        'campaign_run_id' => (int) $run->getKey(),
                        'promotional' => true,
                    ],
                ]);

                $run->update([
                    'notification_id' => $notification->getKey(),
                    'status' => 'dispatched',
                    'completed_at' => now(),
                ]);

                $runCount = (int) $campaign->run_count + 1;
                $next = $this->nextRun($campaign, $scheduledFor, $runCount);

                $campaign->update([
                    'run_count' => $runCount,
                    'last_run_at' => now(),
                    'last_notification_id' => $notification->getKey(),
                    'next_run_at' => $next,
                    'status' => $next === null ? 'completed' : 'active',
                ]);

                return [
                    'notification' => $notification,
                    'run_id' => (int) $run->getKey(),
                ];
            }, 3);
        } catch (Throwable $exception) {
            $this->recordFailure($campaignId, $exception);

            return false;
        }

        if ($result === null) {
            return false;
        }

        $this->push->dispatchNotification($result['notification']);

        return true;
    }

    private function nextRun(
        NotificationCampaign $campaign,
        CarbonImmutable $scheduledFor,
        int $runCount,
    ): ?CarbonImmutable {
        if ($campaign->schedule_kind !== 'recurring') {
            return null;
        }

        if ($campaign->max_runs !== null && $runCount >= (int) $campaign->max_runs) {
            return null;
        }

        $value = max(1, (int) $campaign->interval_value);
        $next = match ((string) $campaign->interval_unit) {
            'minute' => $scheduledFor->addMinutes($value),
            'hour' => $scheduledFor->addHours($value),
            'day' => $scheduledFor->addDays($value),
            'week' => $scheduledFor->addWeeks($value),
            'month' => $scheduledFor->addMonthsNoOverflow($value),
            default => null,
        };

        if (! $next instanceof CarbonImmutable) {
            return null;
        }

        while ($next->lessThanOrEqualTo(now())) {
            $next = match ((string) $campaign->interval_unit) {
                'minute' => $next->addMinutes($value),
                'hour' => $next->addHours($value),
                'day' => $next->addDays($value),
                'week' => $next->addWeeks($value),
                'month' => $next->addMonthsNoOverflow($value),
                default => $next,
            };
        }

        if ($campaign->ends_at !== null && $next->greaterThan($campaign->ends_at)) {
            return null;
        }

        return $next;
    }

    private function recordFailure(int $campaignId, Throwable $exception): void
    {
        $campaign = NotificationCampaign::query()->find($campaignId);
        if (! $campaign instanceof NotificationCampaign || $campaign->next_run_at === null) {
            return;
        }

        NotificationCampaignRun::query()->firstOrCreate(
            [
                'campaign_id' => $campaign->getKey(),
                'scheduled_for' => $campaign->next_run_at,
            ],
            [
                'status' => 'failed',
                'started_at' => now(),
                'completed_at' => now(),
                'error_code' => class_basename($exception),
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ],
        );

        $campaign->update(['next_run_at' => now()->addMinutes(5)]);
    }
}
