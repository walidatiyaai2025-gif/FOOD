<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\RestaurantSaaS\Models\RestaurantLocationProfile;
use App\Domain\RestaurantSaaS\Models\RestaurantSupportAccessGrant;
use App\Enterprise\Health\ModuleHealthService;
use App\Models\AppPreviewSession;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RestaurantOperatorService
{
    public const SUPPORT_REASON_CODES = [
        'technical_support',
        'billing_support',
        'incident_response',
        'migration_support',
    ];

    public const SUPPORT_TTL_MINUTES = [15, 30, 60];

    public function __construct(
        private readonly RestaurantControlPlaneService $controlPlane,
        private readonly ModuleHealthService $health,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, Store> */
    public function stores(): Collection
    {
        return Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.name')
            ->orderBy('stores.id')
            ->get();
    }

    public function store(int $storeId): ?Store
    {
        return Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->where('stores.id', $storeId)
            ->first();
    }

    /**
     * Tenant-safe support snapshot. No token hashes, raw payloads, credentials,
     * personal contact data, or free-form support notes are returned.
     *
     * @return array<string, mixed>
     */
    public function summary(Store $store): array
    {
        $this->assertRestaurantStore($store);

        $controlPlane = $this->controlPlane->context($store);
        $grant = $this->activeGrant($store);
        $scopeKey = 'store:'.(int) $store->getKey();

        $entitlements = EnterpriseModuleEntitlement::query()
            ->where('scope_key', $scopeKey)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS enabled')
            ->first();

        return [
            'control_plane' => $controlPlane,
            'health' => $this->healthSummary(),
            'support_access' => $grant === null ? null : [
                'case_reference' => (string) $grant->case_reference,
                'reason_code' => (string) $grant->reason_code,
                'expires_at' => $this->isoTimestamp($grant->expires_at),
            ],
            'support_sessions' => [
                'active' => AppPreviewSession::query()
                    ->where('store_id', $store->getKey())
                    ->where('support_access', true)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->count(),
            ],
            'entitlements' => [
                'total' => (int) ($entitlements?->getAttribute('total') ?? 0),
                'enabled' => (int) ($entitlements?->getAttribute('enabled') ?? 0),
            ],
            'retention' => [
                'business_data_deletion_allowed' => false,
            ],
        ];
    }

    public function grantSupportAccess(
        Store $store,
        User $actor,
        string $caseReference,
        string $reasonCode,
        int $ttlMinutes,
        Request $request,
    ): RestaurantSupportAccessGrant {
        $this->assertRestaurantStore($store);
        abort_unless(
            RestaurantLocationProfile::query()->where('store_id', $store->getKey())->exists(),
            409,
            'Restaurant location is not provisioned.',
        );
        abort_unless(in_array($reasonCode, self::SUPPORT_REASON_CODES, true), 422);
        abort_unless(in_array($ttlMinutes, self::SUPPORT_TTL_MINUTES, true), 422);

        $caseReference = trim($caseReference);

        return DB::transaction(function () use (
            $store,
            $actor,
            $caseReference,
            $reasonCode,
            $ttlMinutes,
            $request,
        ): RestaurantSupportAccessGrant {
            RestaurantSupportAccessGrant::query()
                ->where('store_id', $store->getKey())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $grant = RestaurantSupportAccessGrant::query()->create([
                'store_id' => $store->getKey(),
                'case_reference' => $caseReference,
                'reason_code' => $reasonCode,
                'granted_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $this->audit->record(
                'restaurant_saas.support_access.granted',
                $actor,
                $store,
                null,
                [
                    'store_id' => (int) $store->getKey(),
                    'case_reference' => $caseReference,
                    'reason_code' => $reasonCode,
                    'ttl_minutes' => $ttlMinutes,
                    'expires_at' => $this->isoTimestamp($grant->expires_at),
                ],
                $request,
            );

            return $grant;
        });
    }

    public function revokeSupportAccess(
        Store $store,
        User $actor,
        Request $request,
    ): RestaurantSupportAccessGrant {
        $this->assertRestaurantStore($store);
        $grant = $this->activeGrant($store);
        abort_unless($grant instanceof RestaurantSupportAccessGrant, 409, 'No active Restaurant SaaS support access exists.');

        $grant->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->getKey(),
        ])->save();

        $this->audit->record(
            'restaurant_saas.support_access.revoked',
            $actor,
            $store,
            [
                'store_id' => (int) $store->getKey(),
                'case_reference' => (string) $grant->case_reference,
                'expires_at' => $this->isoTimestamp($grant->expires_at),
            ],
            [
                'store_id' => (int) $store->getKey(),
                'case_reference' => (string) $grant->case_reference,
                'revoked_at' => $this->isoTimestamp($grant->revoked_at),
            ],
            $request,
        );

        return $grant->fresh();
    }

    public function revokeSupportSessions(Store $store, User $actor, Request $request): int
    {
        $this->assertRestaurantStore($store);

        $count = AppPreviewSession::query()
            ->where('store_id', $store->getKey())
            ->where('support_access', true)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'restaurant_operator_revocation',
                'updated_at' => now(),
            ]);

        $this->audit->record(
            'restaurant_saas.support_sessions.revoked',
            $actor,
            $store,
            null,
            [
                'store_id' => (int) $store->getKey(),
                'revoked_session_count' => $count,
            ],
            $request,
        );

        return $count;
    }

    public function transitionLifecycle(
        Store $store,
        User $actor,
        string $targetState,
        Request $request,
    ): RestaurantLocationProfile {
        $this->assertRestaurantStore($store);
        abort_unless(in_array($targetState, ['CANCELED', 'OFFBOARDED'], true), 422);

        $profile = RestaurantLocationProfile::query()
            ->where('store_id', $store->getKey())
            ->first();
        abort_unless($profile instanceof RestaurantLocationProfile, 409, 'Restaurant location is not provisioned.');

        $current = (string) $profile->lifecycle_state;
        abort_if($current === 'OFFBOARDED', 409, 'Offboarded Restaurant SaaS accounts are immutable.');
        if ($current === $targetState) {
            return $profile;
        }

        if ($targetState === 'OFFBOARDED') {
            abort_unless($current === 'CANCELED', 409, 'Cancel the Restaurant SaaS account before offboarding it.');
        }

        $before = [
            'store_id' => (int) $store->getKey(),
            'lifecycle_state' => $current,
            'canceled_at' => $this->isoTimestamp($profile->canceled_at),
            'offboarded_at' => $this->isoTimestamp($profile->offboarded_at),
        ];

        $values = ['lifecycle_state' => $targetState];
        if ($targetState === 'CANCELED') {
            $values['canceled_at'] = now();
        }
        if ($targetState === 'OFFBOARDED') {
            $values['offboarded_at'] = now();
        }

        $profile->forceFill($values)->save();

        $revokedSupportSessions = 0;
        if ($targetState === 'OFFBOARDED') {
            RestaurantSupportAccessGrant::query()
                ->where('store_id', $store->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $revokedSupportSessions = AppPreviewSession::query()
                ->where('store_id', $store->getKey())
                ->where('support_access', true)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'restaurant_offboarded',
                    'updated_at' => now(),
                ]);
        }

        $profile = $profile->fresh();

        $this->audit->record(
            'restaurant_saas.lifecycle.transitioned',
            $actor,
            $store,
            $before,
            [
                'store_id' => (int) $store->getKey(),
                'lifecycle_state' => (string) $profile->lifecycle_state,
                'canceled_at' => $this->isoTimestamp($profile->canceled_at),
                'offboarded_at' => $this->isoTimestamp($profile->offboarded_at),
                'revoked_support_session_count' => $revokedSupportSessions,
                'business_data_deleted' => false,
            ],
            $request,
        );

        return $profile;
    }

    private function isoTimestamp(string|\DateTimeInterface|null $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    private function activeGrant(Store $store): ?RestaurantSupportAccessGrant
    {
        return RestaurantSupportAccessGrant::query()
            ->where('store_id', $store->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /** @return array{available:bool,overall:string,counts:array<string,int>} */
    private function healthSummary(): array
    {
        try {
            $counts = [];
            foreach ($this->health->all() as $report) {
                $status = $report->status->value;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $overall = ($counts['ERROR'] ?? 0) > 0
                ? 'ERROR'
                : ((($counts['DEGRADED'] ?? 0) + ($counts['MAINTENANCE'] ?? 0)) > 0
                    ? 'DEGRADED'
                    : 'HEALTHY');

            return [
                'available' => true,
                'overall' => $overall,
                'counts' => $counts,
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'overall' => 'UNAVAILABLE',
                'counts' => [],
            ];
        }
    }

    private function assertRestaurantStore(Store $store): void
    {
        $storeType = DB::table('store_types')
            ->where('id', $store->store_type_id)
            ->value('code');

        abort_unless($storeType === 'B2C', 404);
    }
}
