<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SmartOrderingRuntimePolicy
{
    public function __construct(
        private readonly SmartOrderingEnterpriseEntitlementBridge $enterpriseEntitlements,
    ) {}

    public function resolveBySlug(string $slug): ?SmartOrderingSite
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || in_array($slug, (array) config('smart_ordering.reserved_slugs', []), true)) {
            return null;
        }

        $site = $this->activeRetailSites()->where('smart_ordering_sites.slug', $slug)->first();
        if ($site instanceof SmartOrderingSite) {
            return $site;
        }

        $siteId = DB::table('smart_ordering_site_aliases')
            ->where('slug', $slug)
            ->where('redirect_enabled', true)
            ->value('site_id');

        return $siteId === null
            ? null
            : $this->activeRetailSites()->where('smart_ordering_sites.id', (int) $siteId)->first();
    }

    public function resolveByHost(string $host): ?SmartOrderingSite
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }

        $custom = $this->activeRetailSites()
            ->where('smart_ordering_sites.custom_domain', $host)
            ->whereNotNull('smart_ordering_sites.custom_domain_verified_at')
            ->first();

        if ($custom instanceof SmartOrderingSite) {
            return $this->entitled($custom, Module::CUSTOM_DOMAIN) ? $custom : null;
        }

        $baseDomain = $this->normalizeHost((string) config('smart_ordering.base_domain', 'foodex.com'));
        if ($baseDomain === null || $host === $baseDomain) {
            return null;
        }

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

    public function entitled(
        SmartOrderingSite $site,
        string|Module $module,
        ?SmartOrderingBranch $branch = null,
    ): bool {
        $moduleValue = $module instanceof Module ? $module->value : $module;
        if (! in_array($moduleValue, Module::values(), true)) {
            return false;
        }

        $legacyEnabled = null;

        if ($branch !== null) {
            if ((int) $branch->site_id !== (int) $site->id || ! $branch->is_active) {
                return false;
            }

            $branchValue = DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->id)
                ->where('scope_key', (string) $branch->public_id)
                ->where('module', $moduleValue)
                ->value('enabled');

            if ($branchValue !== null) {
                $legacyEnabled = (bool) $branchValue;
            }
        }

        if ($legacyEnabled === null) {
            $legacyEnabled = (bool) (DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->id)
                ->where('scope_key', 'site')
                ->where('module', $moduleValue)
                ->value('enabled') ?? false);
        }

        return $this->enterpriseEntitlements->allows($site, $moduleValue, $legacyEnabled);
    }

    public function effectiveState(SmartOrderingSite $site): RuntimeState
    {
        if (! $site->master_enabled) {
            return RuntimeState::DISABLED;
        }

        return RuntimeState::tryFrom((string) $site->runtime_state) ?? RuntimeState::DISABLED;
    }

    public function allowsPublic(
        SmartOrderingSite $site,
        string|Module $module,
        bool $internal = false,
        bool $pilot = false,
        ?SmartOrderingBranch $branch = null,
    ): bool {
        if (! $this->entitled($site, $module, $branch)) {
            return false;
        }

        return match ($this->effectiveState($site)) {
            RuntimeState::ENABLED => true,
            RuntimeState::PILOT => $pilot || $internal,
            RuntimeState::INTERNAL_TEST => $internal,
            RuntimeState::DISABLED, RuntimeState::EMERGENCY_STOP => false,
        };
    }

    public function allowsRequest(
        SmartOrderingSite $site,
        string|Module $module,
        Request $request,
        ?SmartOrderingBranch $branch = null,
    ): bool {
        $internal = $this->authorizedInternalRequest($site, $request);
        $pilot = $internal || $this->hasRuntimeKey($request, 'pilot');

        return $this->allowsPublic($site, $module, $internal, $pilot, $branch);
    }

    public function assertPublic(
        SmartOrderingSite $site,
        string|Module $module,
        bool $internal = false,
        bool $pilot = false,
        ?SmartOrderingBranch $branch = null,
    ): void {
        abort_unless($this->allowsPublic($site, $module, $internal, $pilot, $branch), 404);
    }

    public function assertRequest(
        SmartOrderingSite $site,
        string|Module $module,
        Request $request,
        ?SmartOrderingBranch $branch = null,
    ): void {
        abort_unless($this->allowsRequest($site, $module, $request, $branch), 404);
    }

    public function branch(SmartOrderingSite $site, string $publicId): SmartOrderingBranch
    {
        return SmartOrderingBranch::query()
            ->where('site_id', $site->id)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /** @return Builder<SmartOrderingSite> */
    private function activeRetailSites(): Builder
    {
        return SmartOrderingSite::query()
            ->select('smart_ordering_sites.*')
            ->join('stores', 'stores.id', '=', 'smart_ordering_sites.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C');
    }

    private function authorizedInternalRequest(SmartOrderingSite $site, Request $request): bool
    {
        $user = $request->user();
        if ($user instanceof User && $user->hasPermission('smart_ordering.manage', (int) $site->store_id)) {
            return true;
        }

        return $this->hasRuntimeKey($request, 'internal');
    }

    private function hasRuntimeKey(Request $request, string $kind): bool
    {
        $configured = (string) config("smart_ordering.{$kind}_key", '');
        if ($configured === '') {
            return false;
        }

        $header = $kind === 'pilot'
            ? 'X-FOODEX-Smart-Ordering-Pilot'
            : 'X-FOODEX-Smart-Ordering-Internal';
        $provided = (string) $request->header($header, '');

        return $provided !== '' && hash_equals($configured, $provided);
    }

    private function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $parsed = parse_url(str_contains($host, '://') ? $host : 'http://'.$host, PHP_URL_HOST);
        if (! is_string($parsed) || $parsed === '') {
            return null;
        }

        return rtrim(strtolower($parsed), '.');
    }
}
