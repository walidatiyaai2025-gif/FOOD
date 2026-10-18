<?php

namespace App\Domain\Assistant\Security;

use App\Domain\Assistant\Data\AiPrincipalContext;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiPrincipalContextResolver
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly RetailerSellOutAccessPolicy $sellOutPolicy,
    ) {}

    /** @param array<string, mixed> $pageContext */
    public function resolve(
        User $user,
        string $locale,
        array $pageContext,
        string $correlationId,
    ): AiPrincipalContext {
        abort_unless((bool) $user->is_active, 403);

        $channel = $this->channel($pageContext['channel'] ?? null);
        $activeStoreId = $this->positiveInt($pageContext['store_id'] ?? null);

        if ($activeStoreId !== null) {
            $channel = $this->scope->assertStore(
                $user,
                $activeStoreId,
                'assistant.use',
                $channel,
            );
        }

        $permittedStoreIds = $this->scope->allowedStoreIds($user, 'assistant.use', $channel);

        if ($activeStoreId !== null) {
            abort_unless(in_array($activeStoreId, $permittedStoreIds, true), 404);
        }

        return new AiPrincipalContext(
            actorUserId: (int) $user->getKey(),
            locale: strtolower($locale) === 'en' ? 'en' : 'ar',
            roleCodes: $this->roleCodes($user, $activeStoreId, $permittedStoreIds),
            permissionCodes: $this->permissionCodes($user, $activeStoreId, $permittedStoreIds),
            businessScope: $this->businessScope($user, $channel, $permittedStoreIds),
            channel: $channel,
            activeStoreId: $activeStoreId,
            permittedStoreIds: $permittedStoreIds,
            pageContext: $this->safePageContext($pageContext, $channel, $activeStoreId),
            retailerDataGrants: $this->sellOutPolicy->grantsForPrincipal(
                $user,
                RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS,
            ),
            correlationId: $correlationId,
        );
    }

    /**
     * @param  list<int>  $permittedStoreIds
     * @return list<string>
     */
    private function roleCodes(User $user, ?int $activeStoreId, array $permittedStoreIds): array
    {
        $codes = $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->pluck('roles.code');

        $storeIds = $activeStoreId === null ? $permittedStoreIds : [$activeStoreId];
        if ($storeIds !== []) {
            $codes = $codes->merge(
                DB::table('user_store_roles')
                    ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
                    ->where('user_store_roles.user_id', $user->getKey())
                    ->whereIn('user_store_roles.store_id', $storeIds)
                    ->where('roles.is_active', true)
                    ->where('roles.scope', 'store')
                    ->pluck('roles.code'),
            );
        }

        return $codes
            ->filter(static fn ($code): bool => $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $permittedStoreIds
     * @return list<string>
     */
    private function permissionCodes(User $user, ?int $activeStoreId, array $permittedStoreIds): array
    {
        if ($activeStoreId !== null) {
            return $user->effectivePermissionCodes($activeStoreId);
        }

        $codes = collect($user->effectivePermissionCodes());
        foreach ($permittedStoreIds as $storeId) {
            $codes = $codes->merge($user->effectivePermissionCodes($storeId));
        }

        return $codes
            ->filter(static fn ($code): bool => $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param list<int> $permittedStoreIds */
    private function businessScope(User $user, ?string $channel, array $permittedStoreIds): string
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return 'platform';
        }

        if ($channel === 'b2b') {
            return 'wholesale';
        }

        if ($channel === 'b2c') {
            return 'retail';
        }

        if ($permittedStoreIds === []) {
            return 'unscoped';
        }

        $channels = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $permittedStoreIds)
            ->pluck('store_types.code')
            ->map(static fn ($code): string => strtolower((string) $code))
            ->unique()
            ->values()
            ->all();

        if ($channels === ['b2b']) {
            return 'wholesale';
        }

        if ($channels === ['b2c']) {
            return 'retail';
        }

        return count($channels) > 1 ? 'mixed' : 'unscoped';
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @return array<string, mixed>
     */
    private function safePageContext(array $pageContext, ?string $channel, ?int $activeStoreId): array
    {
        $path = is_string($pageContext['path'] ?? null) && str_starts_with((string) $pageContext['path'], '/admin')
            ? (string) $pageContext['path']
            : '/admin';

        return [
            'path' => $path,
            'channel' => $channel,
            'store_id' => $activeStoreId,
            'authorized_entities' => is_array($pageContext['authorized_entities'] ?? null)
                ? $pageContext['authorized_entities']
                : [],
        ];
    }

    private function channel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array(strtolower($value), ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages([
                'context.channel' => ['Assistant principal channel must be b2b or b2c.'],
            ]);
        }

        return strtolower($value);
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
