<?php

namespace App\Services;

use App\Models\AppPreviewSession;
use App\Models\Driver;
use App\Models\User;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AppPreviewSessionService
{
    private const DEFAULT_TTL_MINUTES = 15;

    private const MAX_TTL_MINUTES = 30;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContextResolver $tenantResolver,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    /** @return array{session:AppPreviewSession,token:string} */
    public function create(User $actor, array $input, Request $request): array
    {
        $channel = strtolower((string) $input['channel']);
        $targetType = strtolower((string) $input['target_type']);
        $supportAccess = (bool) ($input['support_access'] ?? false);

        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422);
        abort_unless(in_array($targetType, ['customer', 'driver'], true), 422);

        $storeId = $this->resolveAuthorizedStore(
            $actor,
            $channel,
            isset($input['store_id']) ? (int) $input['store_id'] : null,
            $supportAccess,
            $request,
        );

        abort_unless($actor->hasPermission('app_preview.view', $storeId), 403);
        abort_unless(
            $actor->hasPermission("app_preview.impersonate_{$targetType}", $storeId),
            403,
        );

        $target = User::query()
            ->whereKey((int) $input['target_user_id'])
            ->where('is_active', true)
            ->first();
        abort_unless($target instanceof User, 404, 'Active preview target was not found.');

        $this->assertTarget($actor, $target, $targetType, $channel, $storeId);

        $ttl = min(
            max((int) ($input['ttl_minutes'] ?? self::DEFAULT_TTL_MINUTES), 5),
            self::MAX_TTL_MINUTES,
        );
        $plainToken = bin2hex(random_bytes(32));

        $session = AppPreviewSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'actor_user_id' => $actor->getKey(),
            'target_user_id' => $target->getKey(),
            'target_type' => $targetType,
            'channel' => $channel,
            'store_id' => $storeId,
            'mode' => 'read_only',
            'support_access' => $supportAccess,
            'token_hash' => hash('sha256', $plainToken),
            'audit_correlation_id' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->audit->record(
            'app_preview.session.created',
            $actor,
            $session,
            null,
            $this->auditPayload($session),
            $request,
        );

        return ['session' => $session, 'token' => $plainToken];
    }

    public function resolve(string $plainToken, Request $request): AppPreviewSession
    {
        $token = trim($plainToken);
        abort_if($token === '', 401, 'Preview token is required.');

        $session = AppPreviewSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_unless($session instanceof AppPreviewSession, 401, 'Preview session is invalid.');
        abort_if($session->revoked_at !== null, 401, 'Preview session is revoked.');
        $expiresAt = $session->getAttribute('expires_at');
        abort_if(
            $expiresAt === null || CarbonImmutable::parse((string) $expiresAt)->isPast(),
            401,
            'Preview session is expired.',
        );

        $actor = $session->actor_user_id === null
            ? null
            : User::query()
                ->whereKey($session->actor_user_id)
                ->where('is_active', true)
                ->first();
        abort_unless($actor instanceof User, 401, 'Preview session owner is no longer active.');

        $storeId = $session->store_id === null ? null : (int) $session->store_id;
        abort_unless($storeId !== null, 401, 'Preview session store context is invalid.');

        $resolvedStoreId = $this->resolveAuthorizedStore(
            $actor,
            (string) $session->channel,
            $storeId,
            (bool) $session->support_access,
            $request,
        );
        abort_unless($resolvedStoreId === $storeId, 403, 'Preview session store access changed.');
        abort_unless($actor->hasPermission('app_preview.view', $storeId), 403);
        abort_unless(
            $actor->hasPermission("app_preview.impersonate_{$session->target_type}", $storeId),
            403,
        );

        $target = User::query()
            ->whereKey($session->target_user_id)
            ->where('is_active', true)
            ->first();
        abort_unless($target instanceof User, 401, 'Preview target is no longer active.');
        $this->assertTarget(
            $actor,
            $target,
            (string) $session->target_type,
            (string) $session->channel,
            $storeId,
        );

        $firstResolve = $session->last_resolved_at === null;
        $session->forceFill(['last_resolved_at' => now()])->save();

        if ($firstResolve) {
            $this->audit->record(
                'app_preview.session.resolved',
                $actor,
                $session,
                null,
                $this->auditPayload($session),
                $request,
            );
        }

        return $session->fresh();
    }

    public function revoke(
        User $actor,
        AppPreviewSession $session,
        Request $request,
        ?string $reason = null,
    ): AppPreviewSession {
        abort_if($session->revoked_at !== null, 409, 'Preview session is already revoked.');
        abort_unless(
            (int) $session->actor_user_id === (int) $actor->getKey() || $actor->hasRole('SUPER_ADMIN'),
            403,
        );

        $storeId = $session->store_id === null ? null : (int) $session->store_id;
        abort_unless($actor->hasPermission('app_preview.view', $storeId), 403);

        $before = $this->auditPayload($session);
        $session->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason === null || trim($reason) === '' ? 'manual' : trim($reason),
        ])->save();

        $this->audit->record(
            'app_preview.session.revoked',
            $actor,
            $session,
            $before,
            $this->auditPayload($session),
            $request,
        );

        return $session->fresh();
    }

    /** @return array<string,mixed> */
    public function context(AppPreviewSession $session): array
    {
        $target = User::query()->findOrFail($session->target_user_id);

        $context = [
            'session_id' => (string) $session->public_id,
            'target_type' => (string) $session->target_type,
            'auth_mode' => $session->target_type === 'customer' ? 'platform_customer' : 'preview_driver',
            'channel' => (string) $session->channel,
            'store_id' => $session->store_id === null ? null : (int) $session->store_id,
            'commerce_context' => [
                'channel' => (string) $session->channel,
                'store_id' => $session->store_id === null ? null : (int) $session->store_id,
            ],
            'mode' => 'read_only',
            'read_only' => true,
            'support_access' => (bool) $session->support_access,
            'expires_at' => $this->isoDate($session->getAttribute('expires_at')),
            'audit_correlation_id' => (string) $session->audit_correlation_id,
            'target' => [
                'user_id' => (int) $target->getKey(),
                'name' => (string) $target->name,
                'locale' => (string) ($target->locale ?: 'ar'),
            ],
        ];

        if ($session->target_type === 'driver') {
            $driver = Driver::query()
                ->where('user_id', $target->getKey())
                ->where('is_active', true)
                ->first();

            $context['target']['driver_id'] = $driver?->getKey();
        }

        return $context;
    }

    private function resolveAuthorizedStore(
        User $actor,
        string $channel,
        ?int $requestedStoreId,
        bool $supportAccess,
        Request $request,
    ): int {
        if ($channel === 'b2b') {
            $storeId = $this->wholesalePrincipal->storeId();
            abort_if($requestedStoreId !== null && $requestedStoreId !== $storeId, 422, 'Wholesale preview uses the canonical principal store.');

            $this->tenantResolver->wholesale($actor, $storeId);

            return $storeId;
        }

        abort_if($requestedStoreId === null, 422, 'Retail preview requires a store.');
        $this->tenantResolver->retail($actor, $requestedStoreId, $supportAccess, $request);

        return $requestedStoreId;
    }

    private function assertTarget(
        User $actor,
        User $target,
        string $targetType,
        string $channel,
        int $storeId,
    ): void {
        if ($targetType === 'driver') {
            abort_unless(
                $channel === 'b2c',
                404,
                'Driver preview is available only for Retail (B2C) drivers.',
            );

            $driver = Driver::query()
                ->where('user_id', $target->getKey())
                ->where('is_active', true)
                ->first();

            abort_unless($driver instanceof Driver, 404, 'Active driver profile was not found.');
            abort_unless(strtolower((string) $driver->driver_type) === $channel, 404);
            abort_unless((int) ($driver->store_id ?? 0) === $storeId, 404);
            abort_unless($target->hasPermission("deliveries.{$channel}.execute"), 403);

            return;
        }

        if ($channel === 'b2b') {
            $exists = DB::table('b2b_customers')
                ->where('user_id', $target->getKey())
                ->exists()
                || DB::table('platform_customers')
                    ->where('user_id', $target->getKey())
                    ->where('is_active', true)
                    ->exists();

            abort_unless($exists, 404, 'Wholesale customer context was not found.');

            return;
        }

        $retailCustomer = DB::table('b2c_customers')
            ->where('user_id', $target->getKey())
            ->where('store_id', $storeId)
            ->exists();

        if (! $actor->hasRole('SUPER_ADMIN')) {
            abort_unless($retailCustomer, 404, 'Retail customer does not belong to this store.');

            return;
        }

        $platformCustomer = DB::table('platform_customers')
            ->where('user_id', $target->getKey())
            ->where('is_active', true)
            ->exists();

        abort_unless($retailCustomer || $platformCustomer, 404, 'Retail customer context was not found.');
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    /** @return array<string,mixed> */
    private function auditPayload(AppPreviewSession $session): array
    {
        return [
            'session_id' => (string) $session->public_id,
            'actor_user_id' => $session->actor_user_id,
            'target_user_id' => $session->target_user_id,
            'target_type' => (string) $session->target_type,
            'channel' => (string) $session->channel,
            'store_id' => $session->store_id,
            'mode' => (string) $session->mode,
            'support_access' => (bool) $session->support_access,
            'expires_at' => $this->isoDate($session->getAttribute('expires_at')),
            'revoked_at' => $this->isoDate($session->getAttribute('revoked_at')),
            'audit_correlation_id' => (string) $session->audit_correlation_id,
        ];
    }
}
