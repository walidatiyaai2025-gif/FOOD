<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class NotificationCampaignPopupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $this->validatedContext($request);
        $user = $this->optionalUser($request);
        $viewerKey = $this->viewerKey($user, (string) $data['install_id']);

        $campaigns = $this->visibleCampaigns($data, $user)
            ->when(
                $viewerKey !== '',
                function (Builder $query) use ($viewerKey): void {
                    $seenCampaignIds = DB::table('notification_campaign_popup_views')
                        ->where('viewer_key', $viewerKey)
                        ->where('impression_count', '>', 0)
                        ->pluck('campaign_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();

                    if ($seenCampaignIds !== []) {
                        $query->where(function (Builder $frequency) use ($seenCampaignIds): void {
                            $frequency->where('popup_frequency', '!=', 'once_per_user')
                                ->orWhereNotIn('id', $seenCampaignIds);
                        });
                    }
                },
            )
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $locale = (string) ($data['locale'] ?? 'ar');

        return response()->json([
            'data' => $campaigns
                ->map(fn (NotificationCampaign $campaign): array => $this->payload($campaign, $locale))
                ->values(),
        ]);
    }

    public function event(
        Request $request,
        NotificationCampaign $campaign,
    ): Response {
        $data = [
            ...$this->validatedContext($request),
            ...$request->validate([
                'event' => ['required', Rule::in(['impression', 'dismiss', 'open'])],
            ]),
        ];
        $user = $this->optionalUser($request);
        abort_unless(
            $this->visibleCampaigns($data, $user)->whereKey($campaign->getKey())->exists(),
            404,
        );

        $viewerKey = $this->viewerKey($user, (string) $data['install_id']);
        $now = now();

        DB::transaction(function () use ($campaign, $user, $viewerKey, $data, $now): void {
            $existing = DB::table('notification_campaign_popup_views')
                ->where('campaign_id', $campaign->getKey())
                ->where('viewer_key', $viewerKey)
                ->lockForUpdate()
                ->first();

            $event = (string) $data['event'];

            if ($existing === null) {
                DB::table('notification_campaign_popup_views')->insert([
                    'campaign_id' => $campaign->getKey(),
                    'user_id' => $user?->getKey(),
                    'viewer_key' => $viewerKey,
                    'impression_count' => $event === 'impression' ? 1 : 0,
                    'first_impression_at' => $event === 'impression' ? $now : null,
                    'last_impression_at' => $event === 'impression' ? $now : null,
                    'last_dismissed_at' => $event === 'dismiss' ? $now : null,
                    'last_opened_at' => $event === 'open' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            $updates = [
                'user_id' => $user?->getKey() ?? $existing->user_id,
                'updated_at' => $now,
            ];

            if ($event === 'impression') {
                $updates['impression_count'] = DB::raw('impression_count + 1');
                $updates['first_impression_at'] = $existing->first_impression_at ?? $now;
                $updates['last_impression_at'] = $now;
            } elseif ($event === 'dismiss') {
                $updates['last_dismissed_at'] = $now;
            } else {
                $updates['last_opened_at'] = $now;
            }

            DB::table('notification_campaign_popup_views')
                ->where('id', $existing->id)
                ->update($updates);
        }, 3);

        return response()->noContent();
    }

    /** @return array{channel:string,store_id?:int,locale?:string,install_id:string} */
    private function validatedContext(Request $request): array
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['all', 'b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'install_id' => ['required', 'string', 'max:120'],
        ]);

        $channel = (string) $data['channel'];
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($channel === 'all') {
            abort_unless($storeId === null, 422);

            return $data;
        }

        if ($channel === 'b2c') {
            abort_unless($storeId !== null, 422);
        }

        if ($storeId !== null) {
            $enabled = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->where('stores.is_active', true)
                ->where('stores.advertising_enabled', true)
                ->where('store_types.code', strtoupper($channel))
                ->exists();

            abort_unless($enabled, 404);
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return Builder<NotificationCampaign>
     */
    private function visibleCampaigns(array $data, ?User $user): Builder
    {
        $channel = (string) $data['channel'];
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $now = now();

        return NotificationCampaign::query()
            ->whereIn('status', ['active', 'completed'])
            ->whereIn('delivery_channel', ['in_app', 'both'])
            ->whereIn('app', ['all', 'customer'])
            ->where(function (Builder $audience) use ($user): void {
                $audience->whereIn('audience', ['all', 'customer']);

                if ($user instanceof User) {
                    $audience->orWhere(function (Builder $targeted) use ($user): void {
                        $targeted->where('audience', 'user')
                            ->where('user_id', $user->getKey());
                    });
                }
            })
            ->when(
                $channel === 'all',
                fn (Builder $query) => $query->where('target_channel', 'all'),
                fn (Builder $query) => $query->whereIn('target_channel', ['all', $channel]),
            )
            ->when(
                $storeId === null,
                fn (Builder $query) => $query->whereNull('store_id'),
                fn (Builder $query) => $query->where(function (Builder $scope) use ($storeId): void {
                    $scope->whereNull('store_id')->orWhere('store_id', $storeId);
                }),
            )
            ->where(function (Builder $starts) use ($now): void {
                $starts->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $ends) use ($now): void {
                $ends->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    private function optionalUser(Request $request): ?User
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    private function viewerKey(?User $user, string $installId): string
    {
        $identity = $user instanceof User
            ? 'user:'.$user->getKey()
            : 'install:'.$installId;

        return hash('sha256', $identity);
    }

    /** @return array<string, mixed> */
    private function payload(NotificationCampaign $campaign, string $locale): array
    {
        return [
            'id' => (int) $campaign->getKey(),
            'campaign_id' => (int) $campaign->getKey(),
            'target_channel' => (string) $campaign->target_channel,
            'store_id' => $campaign->store_id === null ? null : (int) $campaign->store_id,
            'title' => $locale === 'en' ? $campaign->title_en : $campaign->title_ar,
            'body' => $locale === 'en' ? $campaign->body_en : $campaign->body_ar,
            'image_url' => $this->assetUrl($campaign->image_path),
            'cta_label' => $locale === 'en'
                ? $campaign->popup_cta_label_en
                : $campaign->popup_cta_label_ar,
            'cta_target' => $this->safeTarget($campaign->popup_cta_target),
            'frequency' => (string) ($campaign->popup_frequency ?: 'once_per_session'),
            'starts_at' => $this->dateTime($campaign->starts_at),
            'ends_at' => $this->dateTime($campaign->ends_at),
        ];
    }

    private function safeTarget(mixed $target): ?string
    {
        if (! is_string($target)) {
            return null;
        }

        $value = trim($target);

        return $value !== '' && str_starts_with($value, '/') ? $value : null;
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : null;
    }
}
