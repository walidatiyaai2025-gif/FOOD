<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FeedbackService
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly RestaurantCustomerService $customers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string,mixed> */
    public function submit(
        SmartOrderingSite $site,
        int $customerId,
        int $orderId,
        int $rating,
        ?string $feedback = null,
    ): array {
        abort_unless($this->runtime->entitled($site, Module::REVIEWS), 404);
        abort_unless($rating >= 1 && $rating <= 5, 422);

        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->first(['id', 'status']);
        abort_unless($order !== null, 404);
        abort_unless(strtolower((string) $order->status) === 'completed', 409, 'Feedback is available after order completion.');

        $settings = $this->settings($site);
        abort_unless($settings['request_enabled'], 404);

        return DB::transaction(function () use ($site, $customerId, $orderId, $rating, $feedback, $settings): array {
            $lockedOrder = DB::table('orders')
                ->where('id', $orderId)
                ->where('store_id', $site->store_id)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first(['id']);
            abort_unless($lockedOrder !== null, 404);

            $existing = DB::table('smart_ordering_feedback')
                ->where('site_id', $site->id)
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                abort_unless((int) $existing->customer_id === $customerId, 404);

                return $this->response($site, $customerId, $existing, $settings);
            }

            $offered = $settings['external_review_url'] !== null
                && $rating >= $settings['external_review_min_rating'];

            $feedbackId = (int) DB::table('smart_ordering_feedback')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'site_id' => $site->id,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'rating' => $rating,
                'feedback' => $feedback === null ? null : mb_substr(trim($feedback), 0, 5000),
                'external_review_offered' => $offered,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($rating <= $settings['low_rating_threshold']) {
                DB::table('smart_ordering_service_recovery_cases')->insert([
                    'public_id' => (string) Str::uuid(),
                    'site_id' => $site->id,
                    'feedback_id' => $feedbackId,
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'assigned_user_id' => null,
                    'status' => 'OPEN',
                    'reason' => 'LOW_RATING',
                    'resolution_note' => null,
                    'resolved_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $row = DB::table('smart_ordering_feedback')->where('id', $feedbackId)->first();
            abort_unless($row !== null, 500);

            return $this->response($site, $customerId, $row, $settings);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function configure(
        User $actor,
        SmartOrderingSite $site,
        bool $requestEnabled,
        int $lowRatingThreshold,
        int $externalReviewMinRating,
        ?string $externalReviewUrl,
    ): array {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        abort_unless($lowRatingThreshold >= 1 && $lowRatingThreshold <= 5, 422);
        abort_unless($externalReviewMinRating >= 1 && $externalReviewMinRating <= 5, 422);

        $externalReviewUrl = $externalReviewUrl === null || trim($externalReviewUrl) === ''
            ? null
            : trim($externalReviewUrl);
        if ($externalReviewUrl !== null && filter_var($externalReviewUrl, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['external_review_url' => ['A valid external review URL is required.']]);
        }

        $before = DB::table('smart_ordering_review_settings')->where('site_id', $site->id)->first();

        DB::table('smart_ordering_review_settings')->updateOrInsert(
            ['site_id' => $site->id],
            [
                'request_enabled' => $requestEnabled,
                'low_rating_threshold' => $lowRatingThreshold,
                'external_review_min_rating' => $externalReviewMinRating,
                'external_review_url' => $externalReviewUrl,
                'created_at' => $before?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );

        $after = $this->settings($site);
        $this->audit->record('smart_ordering.reviews.settings_updated', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'request_enabled' => $before?->request_enabled,
            'low_rating_threshold' => $before?->low_rating_threshold,
            'external_review_min_rating' => $before?->external_review_min_rating,
            'external_review_url' => $before?->external_review_url,
        ], [
            'store_id' => (int) $site->store_id,
            ...$after,
        ]);

        return $after;
    }

    public function resolveCase(
        User $actor,
        SmartOrderingSite $site,
        string $publicId,
        string $resolutionNote,
    ): void {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);

        $case = DB::table('smart_ordering_service_recovery_cases')
            ->where('site_id', $site->id)
            ->where('public_id', $publicId)
            ->first();
        abort_unless($case !== null, 404);

        DB::table('smart_ordering_service_recovery_cases')->where('id', $case->id)->update([
            'status' => 'RESOLVED',
            'assigned_user_id' => $actor->getKey(),
            'resolution_note' => mb_substr(trim($resolutionNote), 0, 5000),
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record('smart_ordering.service_recovery.resolved', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'case_public_id' => $publicId,
            'status' => (string) $case->status,
        ], [
            'store_id' => (int) $site->store_id,
            'case_public_id' => $publicId,
            'status' => 'RESOLVED',
        ]);
    }

    /** @return array{request_enabled:bool,low_rating_threshold:int,external_review_min_rating:int,external_review_url:?string} */
    public function settings(SmartOrderingSite $site): array
    {
        $row = DB::table('smart_ordering_review_settings')->where('site_id', $site->id)->first();

        return [
            'request_enabled' => $row === null ? true : (bool) $row->request_enabled,
            'low_rating_threshold' => (int) ($row->low_rating_threshold ?? config('smart_ordering.reviews.low_rating_threshold', 2)),
            'external_review_min_rating' => (int) ($row->external_review_min_rating ?? config('smart_ordering.reviews.external_review_min_rating', 4)),
            'external_review_url' => $row?->external_review_url === null ? null : (string) $row->external_review_url,
        ];
    }

    /** @return array<string,mixed> */
    private function response(SmartOrderingSite $site, int $customerId, object $row, array $settings): array
    {
        $recovery = DB::table('smart_ordering_service_recovery_cases')
            ->where('feedback_id', $row->id)
            ->first(['public_id', 'status']);

        $offerExternal = (bool) $row->external_review_offered;

        return [
            'feedback_id' => (string) $row->public_id,
            'rating' => (int) $row->rating,
            'service_recovery' => $recovery === null ? null : [
                'case_id' => (string) $recovery->public_id,
                'status' => (string) $recovery->status,
            ],
            'external_review_url' => $offerExternal ? $settings['external_review_url'] : null,
            'marketing_followup_allowed' => $this->customers->marketingEligible($site, $customerId),
            'reward_conditioned_on_review' => false,
        ];
    }
}
