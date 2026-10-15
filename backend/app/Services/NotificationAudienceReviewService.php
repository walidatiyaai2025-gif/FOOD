<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\User;

final class NotificationAudienceReviewService
{
    private const CHUNK_SIZE = 200;

    private const SAMPLE_SIZE = 5;

    public function __construct(private readonly NotificationAudience $audience) {}

    /**
     * @return array{count:int,sample:list<array{name:string,email:string}>}
     */
    public function summarize(Notification|NotificationCampaign $target): array
    {
        $count = 0;
        $sample = [];

        User::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($users) use ($target, &$count, &$sample): void {
                foreach ($users as $user) {
                    if (! $user instanceof User || ! $this->matches($user, $target)) {
                        continue;
                    }

                    $count++;
                    if (count($sample) < self::SAMPLE_SIZE) {
                        $sample[] = [
                            'name' => (string) $user->name,
                            'email' => (string) $user->email,
                        ];
                    }
                }
            });

        return ['count' => $count, 'sample' => $sample];
    }

    public function reviewToken(Notification|NotificationCampaign $target, string $action): string
    {
        return hash_hmac('sha256', $this->signature($target, $action), (string) config('app.key'));
    }

    public function validReviewToken(
        Notification|NotificationCampaign $target,
        string $action,
        ?string $token,
    ): bool {
        return is_string($token)
            && $token !== ''
            && hash_equals($this->reviewToken($target, $action), $token);
    }

    private function matches(User $user, Notification|NotificationCampaign $target): bool
    {
        return $this->audience->matchesTarget(
            $user,
            (string) $target->audience,
            (string) $target->app,
            (string) $target->target_channel,
            $target->store_id === null ? null : (int) $target->store_id,
            $target->user_id === null ? null : (int) $target->user_id,
        );
    }

    private function signature(Notification|NotificationCampaign $target, string $action): string
    {
        $payload = [
            'type' => $target instanceof NotificationCampaign ? 'campaign' : 'notification',
            'id' => (int) $target->getKey(),
            'action' => $action,
            'updated_at' => (string) $target->getRawOriginal('updated_at'),
            'audience' => (string) $target->audience,
            'app' => (string) $target->app,
            'target_channel' => (string) $target->target_channel,
            'store_id' => $target->store_id === null ? null : (int) $target->store_id,
            'user_id' => $target->user_id === null ? null : (int) $target->user_id,
            'delivery' => (string) ($target instanceof NotificationCampaign
                ? $target->delivery_channel
                : $target->channel),
            'title_ar' => (string) $target->title_ar,
            'title_en' => (string) $target->title_en,
            'body_ar' => (string) $target->body_ar,
            'body_en' => (string) $target->body_en,
        ];

        if ($target instanceof NotificationCampaign) {
            $payload['schedule_kind'] = (string) $target->schedule_kind;
            $payload['starts_at'] = (string) $target->getRawOriginal('starts_at');
            $payload['ends_at'] = (string) $target->getRawOriginal('ends_at');
            $payload['interval_value'] = $target->interval_value === null ? null : (int) $target->interval_value;
            $payload['interval_unit'] = $target->interval_unit;
            $payload['max_runs'] = $target->max_runs === null ? null : (int) $target->max_runs;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
