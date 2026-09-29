<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotificationCampaignDispatcher
{
    public function __construct(
        private readonly PushDeliveryService $push,
        private readonly AuditLogger $audit,
    ) {}

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
        /** @var array{notification: Notification, campaign: NotificationCampaign, run_id: int, scheduled_for: string}|null $result */
        $result = null;

        try {
            $result = DB::transaction(function () use ($campaignId): ?array {
                $campaign = NotificationCampaign::query()
                    ->whereKey($campaignId)
                    ->lockForUpdate()
                    ->first();

                if (! $campaign instanceof NotificationCampaign
                    || $campaign->status !== 'active'
                    || $campaign->next_run_at === null) {
                    return null;
                }

                $scheduledFor = CarbonImmutable::parse((string) $campaign->next_run_at);
                if ($scheduledFor->isFuture()) {
                    return null;
                }

                $endsAt = $campaign->ends_at === null
                    ? null
                    : CarbonImmutable::parse((string) $campaign->ends_at);

                if ($endsAt !== null && now()->greaterThan($endsAt)) {
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
                    $next = $this->nextRun($campaign, $scheduledFor, (int) $campaign->run_count);
                    $campaign->update([
                        'next_run_at' => $next,
                        'status' => $next === null ? 'completed' : 'active',
                    ]);

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
                    'image_path' => $campaign->image_path,
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
                    'campaign' => $campaign,
                    'run_id' => (int) $run->getKey(),
                    'scheduled_for' => $scheduledFor->toIso8601String(),
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

        $campaign = $result['campaign'];
        $actor = $campaign->created_by === null
            ? null
            : User::query()->find((int) $campaign->created_by);
        $auditRequest = Request::create('/artisan/foodex:dispatch-scheduled-notifications', 'CLI');
        $this->audit->record(
            'notification_campaign.dispatched',
            $actor,
            $campaign,
            null,
            [
                'run_id' => $result['run_id'],
                'notification_id' => (int) $result['notification']->getKey(),
                'scheduled_for' => $result['scheduled_for'],
                'store_id' => $campaign->store_id,
                'target_channel' => $campaign->target_channel,
            ],
            $auditRequest,
        );

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
        $unit = (string) $campaign->interval_unit;
        $next = $this->advance($scheduledFor, $unit, $value);

        if ($next === null) {
            return null;
        }

        while ($next->lessThanOrEqualTo(now())) {
            $candidate = $this->advance($next, $unit, $value);
            if ($candidate === null) {
                return null;
            }

            $next = $candidate;
        }

        $endsAt = $campaign->ends_at === null
            ? null
            : CarbonImmutable::parse((string) $campaign->ends_at);

        if ($endsAt !== null && $next->greaterThan($endsAt)) {
            return null;
        }

        return $next;
    }

    private function advance(CarbonImmutable $date, string $unit, int $value): ?CarbonImmutable
    {
        return match ($unit) {
            'minute' => $date->addMinutes($value),
            'hour' => $date->addHours($value),
            'day' => $date->addDays($value),
            'week' => $date->addWeeks($value),
            'month' => $date->addMonthsNoOverflow($value),
            default => null,
        };
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
