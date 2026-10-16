<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingSiteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function ensureForStore(int $storeId, ?User $actor = null, ?Request $request = null): SmartOrderingSite
    {
        $existing = SmartOrderingSite::query()->where('store_id', $storeId)->first();
        if ($existing instanceof SmartOrderingSite) {
            return $existing;
        }

        return DB::transaction(function () use ($storeId, $actor, $request): SmartOrderingSite {
            $store = Store::query()
                ->select('stores.*')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2C')
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SmartOrderingSite::query()->where('store_id', $storeId)->first();
            if ($existing instanceof SmartOrderingSite) {
                return $existing;
            }

            $slug = $this->availableSlug((string) ($store->code ?: $store->name));
            $site = SmartOrderingSite::query()->create([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => $slug,
                'runtime_state' => RuntimeState::DISABLED->value,
                'master_enabled' => false,
                'created_by' => $actor?->getKey(),
            ]);

            $this->replaceEntitlements($site, []);
            app(SmartOrderingRevisionService::class)->ensurePublished($site, $actor);

            if ($actor instanceof User) {
                $this->audit->record('smart_ordering.site.created', $actor, $site, null, [
                    'store_id' => $storeId,
                    'site_public_id' => (string) $site->public_id,
                    'slug' => $slug,
                    'runtime_state' => RuntimeState::DISABLED->value,
                    'master_enabled' => false,
                ], $request);
            }

            return $site->fresh();
        });
    }

    public function updateRuntime(
        User $actor,
        SmartOrderingSite $site,
        string|RuntimeState $runtimeState,
        bool $masterEnabled,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->assertManage($actor, $site);
        $state = $runtimeState instanceof RuntimeState
            ? $runtimeState
            : RuntimeState::tryFrom($runtimeState);

        if (! $state instanceof RuntimeState) {
            throw ValidationException::withMessages([
                'runtime_state' => [__('smart_ordering.validation.runtime_state')],
            ]);
        }

        $before = ['runtime_state' => $site->runtime_state, 'master_enabled' => (bool) $site->master_enabled];

        $site->forceFill([
            'runtime_state' => $state->value,
            'master_enabled' => $masterEnabled,
        ])->save();

        $this->audit->record('smart_ordering.runtime.updated', $actor, $site, $before, [
            'store_id' => (int) $site->store_id,
            'runtime_state' => $state->value,
            'master_enabled' => $masterEnabled,
        ], $request);

        return $site->fresh();
    }

    /** @param array<string,bool> $values */
    public function replaceEntitlements(
        SmartOrderingSite $site,
        array $values,
        ?User $actor = null,
        ?Request $request = null,
    ): void {
        if ($actor instanceof User) {
            $this->assertManage($actor, $site);
        }

        $modules = Module::values();
        $before = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->id)
            ->where('scope_key', 'site')
            ->pluck('enabled', 'module')
            ->map(fn ($enabled): bool => (bool) $enabled)
            ->all();

        $now = now();
        foreach ($modules as $module) {
            DB::table('smart_ordering_entitlements')->updateOrInsert(
                ['site_id' => $site->id, 'scope_key' => 'site', 'module' => $module],
                [
                    'branch_id' => null,
                    'enabled' => (bool) ($values[$module] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if ($actor instanceof User) {
            $after = DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->id)
                ->where('scope_key', 'site')
                ->pluck('enabled', 'module')
                ->map(fn ($enabled): bool => (bool) $enabled)
                ->all();

            $this->audit->record('smart_ordering.entitlements.updated', $actor, $site, [
                'store_id' => (int) $site->store_id,
                'values' => $before,
            ], [
                'store_id' => (int) $site->store_id,
                'values' => $after,
            ], $request);
        }
    }

    public function setEntitlement(
        User $actor,
        SmartOrderingSite $site,
        string|Module $module,
        bool $enabled,
        ?SmartOrderingBranch $branch = null,
        ?Request $request = null,
    ): void {
        $this->assertManage($actor, $site);
        $moduleValue = $module instanceof Module ? $module->value : $module;
        abort_unless(in_array($moduleValue, Module::values(), true), 422);

        if ($branch !== null) {
            abort_unless((int) $branch->site_id === (int) $site->id && $branch->is_active, 404);
        }

        $scopeKey = $branch === null ? 'site' : (string) $branch->public_id;
        $before = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->id)
            ->where('scope_key', $scopeKey)
            ->where('module', $moduleValue)
            ->value('enabled');

        DB::table('smart_ordering_entitlements')->updateOrInsert(
            ['site_id' => $site->id, 'scope_key' => $scopeKey, 'module' => $moduleValue],
            [
                'branch_id' => $branch?->id,
                'enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->audit->record('smart_ordering.entitlement.updated', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'scope_key' => $scopeKey,
            'module' => $moduleValue,
            'enabled' => $before === null ? null : (bool) $before,
        ], [
            'store_id' => (int) $site->store_id,
            'scope_key' => $scopeKey,
            'module' => $moduleValue,
            'enabled' => $enabled,
        ], $request);
    }

    public function renameSlug(
        User $actor,
        SmartOrderingSite $site,
        string $slug,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->assertManage($actor, $site);
        $slug = strtolower(trim($slug));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
            throw ValidationException::withMessages(['slug' => __('smart_ordering.validation.slug')]);
        }
        if (in_array($slug, (array) config('smart_ordering.reserved_slugs', []), true)) {
            throw ValidationException::withMessages(['slug' => __('smart_ordering.validation.reserved_slug')]);
        }

        $takenBySite = SmartOrderingSite::query()->where('slug', $slug)->whereKeyNot($site->id)->exists();
        $takenByOtherAlias = DB::table('smart_ordering_site_aliases')
            ->where('slug', $slug)
            ->where('site_id', '!=', $site->id)
            ->exists();

        if ($takenBySite || $takenByOtherAlias) {
            throw ValidationException::withMessages(['slug' => __('smart_ordering.validation.slug_taken')]);
        }

        if ((string) $site->slug === $slug) {
            return $site;
        }

        $before = (string) $site->slug;
        DB::transaction(function () use ($site, $slug, $before): void {
            DB::table('smart_ordering_site_aliases')
                ->where('site_id', $site->id)
                ->where('slug', $slug)
                ->delete();

            DB::table('smart_ordering_site_aliases')->updateOrInsert(
                ['slug' => $before],
                ['site_id' => $site->id, 'redirect_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            );
            $site->forceFill(['slug' => $slug])->save();
        });

        $this->audit->record('smart_ordering.slug.renamed', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'slug' => $before,
        ], [
            'store_id' => (int) $site->store_id,
            'slug' => $slug,
        ], $request);

        return $site->fresh();
    }

    /**
     * @return array{site:SmartOrderingSite,verification_token:?string}
     */
    public function updateCustomDomain(
        User $actor,
        SmartOrderingSite $site,
        ?string $domain,
        ?Request $request = null,
    ): array {
        $this->assertManage($actor, $site);
        $domain = $this->normalizeCustomDomain($domain);

        if ($domain !== null && SmartOrderingSite::query()
            ->where('custom_domain', $domain)
            ->whereKeyNot($site->id)
            ->exists()) {
            throw ValidationException::withMessages(['custom_domain' => __('smart_ordering.validation.domain_taken')]);
        }

        $token = $domain === null ? null : Str::random(48);
        $before = $site->only(['custom_domain', 'custom_domain_verified_at']);

        $site->forceFill([
            'custom_domain' => $domain,
            'custom_domain_verification_token_hash' => $token === null ? null : hash('sha256', $token),
            'custom_domain_verified_at' => null,
        ])->save();

        $this->audit->record('smart_ordering.custom_domain.updated', $actor, $site, [
            'store_id' => (int) $site->store_id,
            ...$before,
        ], [
            'store_id' => (int) $site->store_id,
            'custom_domain' => $domain,
            'custom_domain_verified_at' => null,
        ], $request);

        return ['site' => $site->fresh(), 'verification_token' => $token];
    }

    public function verifyCustomDomain(
        User $actor,
        SmartOrderingSite $site,
        string $verificationToken,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->assertManage($actor, $site);

        $storedHash = (string) ($site->custom_domain_verification_token_hash ?? '');
        $verified = $site->custom_domain !== null
            && $storedHash !== ''
            && $verificationToken !== ''
            && hash_equals($storedHash, hash('sha256', $verificationToken));

        if (! $verified) {
            throw ValidationException::withMessages([
                'verification_token' => [__('smart_ordering.validation.domain_verification')],
            ]);
        }

        $site->forceFill(['custom_domain_verified_at' => now()])->save();

        $this->audit->record('smart_ordering.custom_domain.verified', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'custom_domain' => (string) $site->custom_domain,
            'verified' => false,
        ], [
            'store_id' => (int) $site->store_id,
            'custom_domain' => (string) $site->custom_domain,
            'verified' => true,
        ], $request);

        return $site->fresh();
    }

    private function normalizeCustomDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $domain = rtrim($domain, '.');
        if (! $this->isSafeHost($domain)) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('smart_ordering.validation.domain')],
            ]);
        }

        $baseDomain = $this->canonicalConfiguredHost((string) config('smart_ordering.base_domain', 'foodex.com'));
        if ($baseDomain !== null
            && ($domain === $baseDomain || str_ends_with($domain, '.'.$baseDomain))) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('smart_ordering.validation.domain')],
            ]);
        }

        return $domain;
    }

    private function canonicalConfiguredHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        return $this->isSafeHost($host) ? $host : null;
    }

    private function isSafeHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        if (preg_match('/[\\s\\/:?#@*]/', $host) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function availableSlug(string $source): string
    {
        $base = Str::slug($source);
        if ($base === '' || in_array($base, (array) config('smart_ordering.reserved_slugs', []), true)) {
            $base = 'restaurant-'.strtolower(Str::random(8));
        }
        $base = Str::limit($base, 50, '');

        $candidate = $base;
        $suffix = 1;
        while (SmartOrderingSite::query()->where('slug', $candidate)->exists()
            || DB::table('smart_ordering_site_aliases')->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 54, '').'-'.$suffix;
        }

        return $candidate;
    }

    private function assertManage(User $actor, SmartOrderingSite $site): void
    {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
    }
}
