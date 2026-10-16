<?php

namespace App\Enterprise\Search;

use App\Models\EnterpriseOrganization;
use App\Models\User;

final class OrganizationGlobalSearchProvider implements GlobalSearchProvider
{
    public function search(User $actor, string $query, int $limit): array
    {
        if (! $actor->hasPermission('platform.manage')) {
            return [];
        }

        return EnterpriseOrganization::query()
            ->where(function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('code', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'is_active'])
            ->map(static fn (EnterpriseOrganization $organization): array => [
                'type' => 'organization',
                'type_label' => __('global_search.types.organization'),
                'title' => (string) $organization->name,
                'subtitle' => $organization->is_active
                    ? __('global_search.states.active')
                    : __('global_search.states.inactive'),
                'url' => route('admin.enterprise-organizations.show', $organization, false),
            ])
            ->values()
            ->all();
    }
}
