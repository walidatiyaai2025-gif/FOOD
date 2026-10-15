<?php

namespace App\Domain\SmartOrdering;

use App\Models\SmartOrderingBranchScope;
use App\Models\SmartOrderingSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingSiteResolver
{
    public function __construct(private readonly SmartOrderingRuntimePolicy $runtime) {}

    public function resolveHost(string $host): ?ResolvedSmartOrderingContext
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }

        $customDomain = DB::table('smart_ordering_domains')
            ->where('domain', $host)
            ->where('status', 'verified')
            ->where('enabled', true)
            ->first();

        if ($customDomain !== null) {
            $site = $this->activeRetailSite((int) $customDomain->site_id);
            if ($site !== null && $this->runtime->effectiveEntitlement($site, Module::CUSTOM_DOMAIN)) {
                return new ResolvedSmartOrderingContext(
                    site: $site,
                    resolvedFromCustomDomain: true,
                );
            }

            return null;
        }

        $baseDomain = $this->normalizeHost((string) config('smart_ordering.base_domain', 'foodex.com'));
        if ($baseDomain === null || $host === $baseDomain || ! str_ends_with($host, '.'.$baseDomain)) {
            return null;
        }

        $slug = Str::beforeLast($host, '.'.$baseDomain);
        if ($slug === '' || str_contains($slug, '.') || $this->isReservedSlug($slug)) {
            return null;
        }

        return $this->resolveSlug($slug);
    }

    public function resolveSlug(string $slug): ?ResolvedSmartOrderingContext
    {
        $slug = $this->normalizeSlug($slug);

        if ($slug === null || $this->isReservedSlug($slug)) {
            return null;
        }

        $siteId = DB::table('smart_ordering_sites')->where('slug', $slug)->value('id');
        if ($siteId !== null) {
            $site = $this->activeRetailSite((int) $siteId);

            return $site === null ? null : new ResolvedSmartOrderingContext($site);
        }

        $alias = DB::table('smart_ordering_slug_aliases')->where('slug', $slug)->first();
        if ($alias === null) {
            return null;
        }

        $site = $this->activeRetailSite((int) $alias->site_id);

        return $site === null
            ? null
            : new ResolvedSmartOrderingContext(site: $site, resolvedFromAlias: true);
    }

    public function resolveBranch(
        SmartOrderingSite $site,
        ?string $branchPublicId,
    ): ?SmartOrderingBranchScope {
        if ($branchPublicId === null || trim($branchPublicId) === '' || ! Str::isUuid($branchPublicId)) {
            return null;
        }

        return SmartOrderingBranchScope::query()
            ->where('site_id', $site->getKey())
            ->where('public_id', $branchPublicId)
            ->where('is_active', true)
            ->first();
    }

    public function assertSlugAvailable(string $slug, ?SmartOrderingSite $except = null): string
    {
        $normalized = $this->normalizeSlug($slug);
        if ($normalized === null || $this->isReservedSlug($normalized)) {
            throw ValidationException::withMessages([
                'slug' => ['The Smart Ordering slug is invalid or reserved.'],
            ]);
        }

        $current = DB::table('smart_ordering_sites')->where('slug', $normalized);
        if ($except !== null) {
            $current->where('id', '<>', $except->getKey());
        }

        if ($current->exists() || DB::table('smart_ordering_slug_aliases')->where('slug', $normalized)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The Smart Ordering slug is already in use or retained as a safe alias.'],
            ]);
        }

        return $normalized;
    }

    public function isReservedSlug(string $slug): bool
    {
        return in_array(
            strtolower($slug),
            array_map('strtolower', (array) config('smart_ordering.reserved_slugs', [])),
            true,
        );
    }

    public function normalizeSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '' || strlen($slug) > 63) {
            return null;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) === 1
            ? $slug
            : null;
    }

    public function normalizeDomain(string $domain): string
    {
        $host = $this->normalizeHost($domain);

        if ($host === null
            || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw ValidationException::withMessages([
                'domain' => ['A valid DNS hostname is required.'],
            ]);
        }

        return $host;
    }

    private function activeRetailSite(int $siteId): ?SmartOrderingSite
    {
        return SmartOrderingSite::query()
            ->select('smart_ordering_sites.*')
            ->join('stores', 'stores.id', '=', 'smart_ordering_sites.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('smart_ordering_sites.id', $siteId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->first();
    }

    private function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $parsed = parse_url(str_contains($host, '://') ? $host : 'http://'.$host, PHP_URL_HOST);
        $host = is_string($parsed) ? rtrim(strtolower($parsed), '.') : '';

        return $host === '' ? null : $host;
    }
}
