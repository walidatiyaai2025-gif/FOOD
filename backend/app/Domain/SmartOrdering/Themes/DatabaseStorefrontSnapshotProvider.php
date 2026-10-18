<?php

namespace App\Domain\SmartOrdering\Themes;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class DatabaseStorefrontSnapshotProvider implements StorefrontSnapshotProvider
{
    public function __construct(
        private readonly RestaurantMenuContentSnapshotService $content,
        private readonly ConfigStorefrontSnapshotProvider $fallback,
    ) {}

    public function published(string $siteKey): ?array
    {
        $site = $this->site($siteKey);
        if (! $site instanceof SmartOrderingSite) {
            return $this->fallback->published($siteKey);
        }

        $revision = SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->latest('id')
            ->first();

        return $revision instanceof SmartOrderingRevision
            ? $this->content->snapshot($site, $revision)
            : null;
    }

    public function revision(string $siteKey, string $revision): ?array
    {
        $site = $this->site($siteKey);
        if (! $site instanceof SmartOrderingSite) {
            return $this->fallback->revision($siteKey, $revision);
        }

        $status = $revision === 'draft' ? 'draft' : 'published';
        $model = SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', $status)
            ->latest('id')
            ->first();

        if (! $model instanceof SmartOrderingRevision && $status === 'draft') {
            $model = SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'published')
                ->latest('id')
                ->first();
        }

        return $model instanceof SmartOrderingRevision
            ? $this->content->snapshot($site, $model)
            : null;
    }

    private function site(string $siteKey): ?SmartOrderingSite
    {
        return SmartOrderingSite::query()
            ->where(function (Builder $query) use ($siteKey): void {
                $query->where('slug', $siteKey);
                if (Str::isUuid($siteKey)) {
                    $query->orWhere('public_id', $siteKey);
                }
            })
            ->first();
    }
}
