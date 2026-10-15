<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Illuminate\Support\Facades\DB;

final class SmartOrderingRuntimePolicy
{
    public function resolveBySlug(string $slug): ?SmartOrderingSite
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || in_array($slug, (array) config('smart_ordering.reserved_slugs', []), true)) {
            return null;
        }

        $site = SmartOrderingSite::query()->where('slug', $slug)->first();
        if ($site instanceof SmartOrderingSite) {
            return $site;
        }

        $siteId = DB::table('smart_ordering_site_aliases')
            ->where('slug', $slug)
            ->where('redirect_enabled', true)
            ->value('site_id');

        return $siteId === null ? null : SmartOrderingSite::query()->find((int) $siteId);
    }

    public function resolveByHost(string $host): ?SmartOrderingSite
    {
        $host = strtolower(trim(explode(':', $host, 2)[0]));
        if ($host === '') {
            return null;
        }

        $custom = SmartOrderingSite::query()
            ->where('custom_domain', $host)
            ->whereNotNull('custom_domain_verified_at')
            ->first();
        if ($custom instanceof SmartOrderingSite) {
            return $custom;
        }

        $baseDomain = strtolower(trim((string) config('smart_ordering.base_domain', 'foodex.com')));
        $suffix = '.'.$baseDomain;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));
        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        return $this->resolveBySlug($slug);
    }

    public function entitled(SmartOrderingSite $site, string $module): bool
    {
        if (! in_array($module, (array) config('smart_ordering.entitlements', []), true)) {
            return false;
        }

        return (bool) DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->id)
            ->where('module', $module)
            ->value('enabled');
    }

    public function allowsPublic(
        SmartOrderingSite $site,
        string $module,
        bool $internal = false,
        bool $pilot = false,
    ): bool {
        if (! $site->master_enabled || ! $this->entitled($site, $module)) {
            return false;
        }

        return match ((string) $site->runtime_state) {
            'ENABLED' => true,
            'PILOT' => $pilot || $internal,
            'INTERNAL_TEST' => $internal,
            default => false,
        };
    }

    public function assertPublic(
        SmartOrderingSite $site,
        string $module,
        bool $internal = false,
        bool $pilot = false,
    ): void {
        abort_unless($this->allowsPublic($site, $module, $internal, $pilot), 404);
    }

    public function branch(SmartOrderingSite $site, string $publicId): SmartOrderingBranch
    {
        return SmartOrderingBranch::query()
            ->where('site_id', $site->id)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
