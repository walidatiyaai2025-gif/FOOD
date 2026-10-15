<?php

namespace App\Services;

use App\Models\EnterpriseSavedView;
use App\Models\User;
use App\Support\EnterpriseGridState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EnterpriseSavedViewService
{
    private const SCOPE_TYPES = [
        'platform',
        'organization',
        'company',
        'legal_entity',
        'business_unit',
        'store',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, EnterpriseSavedView>
     */
    public function visible(
        User $actor,
        string $surfaceKey,
        string $scopeType,
        ?int $scopeId,
    ): Collection
    {
        $surfaceKey = $this->surfaceKey($surfaceKey);
        $scopeKey = $this->authorizeScope($actor, $scopeType, $scopeId);

        return EnterpriseSavedView::query()
            ->where('surface_key', $surfaceKey)
            ->where('scope_key', $scopeKey)
            ->where(function ($query) use ($actor): void {
                $query->where('user_id', $actor->getKey())
                    ->orWhere('visibility', 'shared');
            })
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    public function stateToken(
        string $surfaceKey,
        string $scopeType,
        ?int $scopeId,
        EnterpriseGridState $state,
    ): string
    {
        $surfaceKey = $this->surfaceKey($surfaceKey);
        $scopeKey = $this->scopeKey($scopeType, $scopeId);

        return Crypt::encryptString(json_encode([
            'version' => 1,
            'surface_key' => $surfaceKey,
            'scope_key' => $scopeKey,
            'state' => $state->toSavedState(),
        ], JSON_THROW_ON_ERROR));
    }

    public function createFromToken(
        User $actor,
        string $surfaceKey,
        string $scopeType,
        ?int $scopeId,
        string $name,
        string $visibility,
        string $token,
    ): EnterpriseSavedView
    {
        $surfaceKey = $this->surfaceKey($surfaceKey);
        $scopeKey = $this->authorizeScope($actor, $scopeType, $scopeId);
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => [__('enterprise_grid.name_required')]]);
        }
        $name = mb_substr($name, 0, 120);

        if (in_array($visibility, ['private', 'shared'], true) === false) {
            throw ValidationException::withMessages(['visibility' => [__('enterprise_grid.invalid_visibility')]]);
        }

        $state = $this->stateFromToken($token, $surfaceKey, $scopeKey);

        $existing = EnterpriseSavedView::query()
            ->where('user_id', $actor->getKey())
            ->where('surface_key', $surfaceKey)
            ->where('scope_key', $scopeKey)
            ->where('name', $name)
            ->first();

        $view = EnterpriseSavedView::query()->updateOrCreate(
            [
                'user_id' => $actor->getKey(),
                'surface_key' => $surfaceKey,
                'scope_key' => $scopeKey,
                'name' => $name,
            ],
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'visibility' => $visibility,
                'search' => $state->search,
                'filters' => $state->filters,
                'sort_field' => $state->sortField,
                'sort_direction' => $state->sortDirection,
                'columns' => $state->columns,
                'per_page' => $state->perPage,
            ],
        );

        $this->audit->record(
            $existing instanceof EnterpriseSavedView
                ? 'enterprise.saved_view.updated'
                : 'enterprise.saved_view.created',
            $actor,
            $view,
            null,
            [
                'surface_key' => $surfaceKey,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'visibility' => $visibility,
            ],
        );

        return $view->refresh();
    }

    public function delete(User $actor, EnterpriseSavedView $view): void
    {
        abort_unless((int) $view->user_id === (int) $actor->getKey(), 403);
        $this->authorizeScope($actor, (string) $view->scope_type, $view->scope_id);

        $this->audit->record(
            'enterprise.saved_view.deleted',
            $actor,
            $view,
            [
                'surface_key' => $view->surface_key,
                'scope_type' => $view->scope_type,
                'scope_id' => $view->scope_id,
                'visibility' => $view->visibility,
            ],
            null,
        );

        $view->delete();
    }

    /** @return array<string, mixed> */
    public function queryFor(EnterpriseSavedView $view): array
    {
        return EnterpriseGridState::fromSavedState([
            'search' => $view->search,
            'filters' => $view->filters ?? [],
            'sort' => [
                'field' => $view->sort_field,
                'direction' => $view->sort_direction,
            ],
            'columns' => $view->columns ?? [],
            'per_page' => $view->per_page,
        ])->toQuery();
    }

    private function stateFromToken(string $token, string $surfaceKey, string $scopeKey): EnterpriseGridState
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'state_token' => [__('enterprise_grid.invalid_state')],
            ]);
        }

        if (is_array($decoded) === false
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['surface_key'] ?? null) !== $surfaceKey
            || ($decoded['scope_key'] ?? null) !== $scopeKey
            || is_array($decoded['state'] ?? null) === false) {
            throw ValidationException::withMessages([
                'state_token' => [__('enterprise_grid.invalid_state')],
            ]);
        }

        return EnterpriseGridState::fromSavedState($decoded['state']);
    }

    private function surfaceKey(string $surfaceKey): string
    {
        $surfaceKey = trim($surfaceKey);

        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/', $surfaceKey) !== 1) {
            throw ValidationException::withMessages([
                'surface_key' => [__('enterprise_grid.invalid_surface')],
            ]);
        }

        return $surfaceKey;
    }

    private function authorizeScope(User $actor, string $scopeType, ?int $scopeId): string
    {
        abort_unless((bool) $actor->is_active, 403);
        $scopeKey = $this->scopeKey($scopeType, $scopeId);

        if ($scopeType === 'platform') {
            abort_unless($actor->hasPermission('platform.manage'), 403);

            return $scopeKey;
        }

        $scopeStores = $this->scopeStoreQuery($scopeType, (int) $scopeId);

        if ($actor->hasRole('SUPER_ADMIN')) {
            abort_unless((clone $scopeStores)->exists(), 403);

            return $scopeKey;
        }

        $storeRoleAccess = (clone $scopeStores)
            ->join('user_store_roles as saved_view_usr', 'saved_view_usr.store_id', '=', 'saved_view_stores.id')
            ->join('roles as saved_view_roles', 'saved_view_roles.id', '=', 'saved_view_usr.role_id')
            ->where('saved_view_usr.user_id', $actor->getKey())
            ->where('saved_view_roles.is_active', true)
            ->where('saved_view_roles.scope', 'store')
            ->exists();

        if ($storeRoleAccess) {
            return $scopeKey;
        }

        $globalRoles = $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->pluck('roles.code')
            ->all();

        $allowedChannels = [];
        foreach ((array) config('admin.channels', []) as $channel => $definition) {
            if (is_string($channel) === false || is_array($definition) === false) {
                continue;
            }

            $allowedRoles = array_values(array_filter((array) ($definition['global_roles'] ?? []), 'is_string'));
            if (array_intersect($globalRoles, $allowedRoles) !== []) {
                $allowedChannels[] = strtoupper($channel);
            }
        }

        if ($allowedChannels !== []) {
            $globalAccess = (clone $scopeStores)
                ->join('store_types as saved_view_store_types', 'saved_view_store_types.id', '=', 'saved_view_stores.store_type_id')
                ->whereIn('saved_view_store_types.code', $allowedChannels)
                ->exists();

            if ($globalAccess) {
                return $scopeKey;
            }
        }

        abort(403);
    }

    private function scopeKey(string $scopeType, ?int $scopeId): string
    {
        if (in_array($scopeType, self::SCOPE_TYPES, true) === false) {
            throw ValidationException::withMessages([
                'scope_type' => [__('enterprise_grid.invalid_scope')],
            ]);
        }

        if ($scopeType === 'platform') {
            if ($scopeId !== null && $scopeId !== 0) {
                throw ValidationException::withMessages([
                    'scope_id' => [__('enterprise_grid.invalid_scope')],
                ]);
            }

            return 'platform:0';
        }

        if ($scopeId === null || $scopeId < 1) {
            throw ValidationException::withMessages([
                'scope_id' => [__('enterprise_grid.invalid_scope')],
            ]);
        }

        return $scopeType.':'.$scopeId;
    }

    private function scopeStoreQuery(string $scopeType, int $scopeId): Builder
    {
        $query = DB::table('enterprise_store_scopes as saved_view_scope')
            ->join('stores as saved_view_stores', 'saved_view_stores.id', '=', 'saved_view_scope.store_id')
            ->join('enterprise_business_units as saved_view_bu', 'saved_view_bu.id', '=', 'saved_view_scope.business_unit_id')
            ->join('enterprise_legal_entities as saved_view_legal', 'saved_view_legal.id', '=', 'saved_view_bu.legal_entity_id')
            ->join('enterprise_companies as saved_view_company', 'saved_view_company.id', '=', 'saved_view_legal.company_id')
            ->join('enterprise_organizations as saved_view_org', 'saved_view_org.id', '=', 'saved_view_company.organization_id')
            ->where('saved_view_stores.is_active', true)
            ->where('saved_view_bu.is_active', true)
            ->where('saved_view_legal.is_active', true)
            ->where('saved_view_company.is_active', true)
            ->where('saved_view_org.is_active', true);

        return match ($scopeType) {
            'store' => $query->where('saved_view_scope.store_id', $scopeId),
            'business_unit' => $query->where('saved_view_bu.id', $scopeId),
            'legal_entity' => $query->where('saved_view_legal.id', $scopeId),
            'company' => $query->where('saved_view_company.id', $scopeId),
            'organization' => $query->where('saved_view_org.id', $scopeId),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
