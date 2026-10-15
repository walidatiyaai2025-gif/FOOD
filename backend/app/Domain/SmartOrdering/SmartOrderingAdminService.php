<?php

namespace App\Domain\SmartOrdering;

use App\Models\SmartOrderingBranchScope;
use App\Models\SmartOrderingRevision;
use App\Models\SmartOrderingSite;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingAdminService
{
    public function __construct(
        private readonly OperationalTenantScope $tenantScope,
        private readonly SmartOrderingSiteResolver $resolver,
        private readonly SmartOrderingConfigurationService $configuration,
        private readonly AuditLogger $audit,
    ) {}

    public function siteForStore(User $actor, int $storeId, string $permission = 'smart_ordering.view'): ?SmartOrderingSite
    {
        $this->tenantScope->assertStore($actor, $storeId, $permission, 'b2c');

        return SmartOrderingSite::query()->where('store_id', $storeId)->first();
    }

    public function requireSite(User $actor, int $storeId, string $permission = 'smart_ordering.view'): SmartOrderingSite
    {
        $site = $this->siteForStore($actor, $storeId, $permission);
        abort_unless($site !== null, 404);

        return $site;
    }

    public function provision(
        User $actor,
        int $storeId,
        string $slug,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->tenantScope->assertStore($actor, $storeId, 'smart_ordering.manage', 'b2c');
        $slug = $this->resolver->assertSlugAvailable($slug);

        return DB::transaction(function () use ($actor, $storeId, $slug, $request): SmartOrderingSite {
            Store::query()->whereKey($storeId)->lockForUpdate()->firstOrFail();

            if (SmartOrderingSite::query()->where('store_id', $storeId)->exists()) {
                throw ValidationException::withMessages([
                    'store' => ['This restaurant already has a Smart Ordering site.'],
                ]);
            }

            $site = SmartOrderingSite::query()->create([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => $slug,
                'master_enabled' => false,
                'runtime_state' => RuntimeState::DISABLED->value,
            ]);

            $now = now();
            foreach (Module::cases() as $module) {
                DB::table('smart_ordering_entitlements')->insert([
                    'site_id' => $site->getKey(),
                    'branch_scope_id' => null,
                    'scope_key' => 'site',
                    'module' => $module->value,
                    'enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->configuration->ensurePublished($site, $actor, $request);
            $this->audit->record(
                'smart_ordering.site.created',
                $actor,
                $site,
                null,
                $this->siteAuditPayload($site),
                $request,
            );

            return $site->fresh();
        });
    }

    public function renameSlug(
        User $actor,
        SmartOrderingSite $site,
        string $slug,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');

        if ($this->resolver->normalizeSlug($slug) === (string) $site->slug) {
            return $site;
        }

        $slug = $this->resolver->assertSlugAvailable($slug, $site);
        $before = $this->siteAuditPayload($site);

        DB::transaction(function () use ($site, $slug): void {
            $locked = SmartOrderingSite::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            DB::table('smart_ordering_slug_aliases')->insert([
                'site_id' => $locked->getKey(),
                'slug' => (string) $locked->slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->forceFill(['slug' => $slug])->save();
        });

        $site = $site->fresh();
        $this->audit->record(
            'smart_ordering.site.slug_renamed',
            $actor,
            $site,
            $before,
            $this->siteAuditPayload($site),
            $request,
        );

        return $site;
    }

    public function updateRuntime(
        User $actor,
        SmartOrderingSite $site,
        bool $masterEnabled,
        RuntimeState $state,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');
        $before = $this->siteAuditPayload($site);

        $site->forceFill([
            'master_enabled' => $masterEnabled,
            'runtime_state' => $state->value,
        ])->save();

        $site = $site->fresh();
        $this->audit->record(
            'smart_ordering.runtime.changed',
            $actor,
            $site,
            $before,
            $this->siteAuditPayload($site),
            $request,
        );

        return $site;
    }

    public function setEntitlement(
        User $actor,
        SmartOrderingSite $site,
        Module $module,
        bool $enabled,
        ?SmartOrderingBranchScope $branch = null,
        ?Request $request = null,
    ): void {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');

        if ($branch !== null) {
            abort_unless((int) $branch->site_id === (int) $site->getKey(), 404);
        }

        $scopeKey = $branch === null ? 'site' : (string) $branch->public_id;
        $before = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->getKey())
            ->where('scope_key', $scopeKey)
            ->where('module', $module->value)
            ->value('enabled');

        DB::table('smart_ordering_entitlements')->updateOrInsert(
            [
                'site_id' => $site->getKey(),
                'scope_key' => $scopeKey,
                'module' => $module->value,
            ],
            [
                'branch_scope_id' => $branch?->getKey(),
                'enabled' => $enabled,
                'updated_at' => now(),
            ],
        );

        $this->audit->record(
            'smart_ordering.entitlement.changed',
            $actor,
            $site,
            [
                'store_id' => (int) $site->store_id,
                'module' => $module->value,
                'scope' => $scopeKey,
                'enabled' => $before === null ? null : (bool) $before,
            ],
            [
                'store_id' => (int) $site->store_id,
                'module' => $module->value,
                'scope' => $scopeKey,
                'enabled' => $enabled,
            ],
            $request,
        );
    }

    public function createBranchScope(
        User $actor,
        SmartOrderingSite $site,
        string $code,
        string $name,
        ?Request $request = null,
    ): SmartOrderingBranchScope {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');

        $code = strtoupper(trim($code));
        $name = trim($name);

        if ($code === '' || strlen($code) > 64 || $name === '') {
            throw ValidationException::withMessages(['code' => ['A branch scope code and name are required.']]);
        }

        if (SmartOrderingBranchScope::query()->where('site_id', $site->getKey())->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['Branch scope code must be unique within the restaurant.']]);
        }

        $branch = SmartOrderingBranchScope::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->getKey(),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);

        $this->audit->record(
            'smart_ordering.branch_scope.created',
            $actor,
            $site,
            null,
            [
                'store_id' => (int) $site->store_id,
                'branch_public_id' => (string) $branch->public_id,
                'code' => (string) $branch->code,
            ],
            $request,
        );

        return $branch;
    }

    /** @return array{public_id:string,domain:string,verification_token:string,status:string} */
    public function requestCustomDomain(
        User $actor,
        SmartOrderingSite $site,
        string $domain,
        ?Request $request = null,
    ): array {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');

        $domain = $this->resolver->normalizeDomain($domain);
        if (DB::table('smart_ordering_domains')->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => ['This custom domain is already registered.']]);
        }

        $token = Str::random(48);
        $publicId = (string) Str::uuid();

        DB::table('smart_ordering_domains')->insert([
            'public_id' => $publicId,
            'site_id' => $site->getKey(),
            'domain' => $domain,
            'status' => 'pending',
            'enabled' => false,
            'verification_token_hash' => hash('sha256', $token),
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            'smart_ordering.custom_domain.requested',
            $actor,
            $site,
            null,
            ['store_id' => (int) $site->store_id, 'domain' => $domain, 'status' => 'pending'],
            $request,
        );

        return [
            'public_id' => $publicId,
            'domain' => $domain,
            'verification_token' => $token,
            'status' => 'pending',
        ];
    }

    public function verifyCustomDomain(
        User $actor,
        SmartOrderingSite $site,
        string $domainPublicId,
        string $token,
        ?Request $request = null,
    ): void {
        $this->tenantScope->assertStore($actor, (int) $site->store_id, 'smart_ordering.manage', 'b2c');

        $domain = DB::table('smart_ordering_domains')
            ->where('public_id', $domainPublicId)
            ->where('site_id', $site->getKey())
            ->first();

        abort_unless($domain !== null, 404);

        $valid = $token !== '' && hash_equals((string) $domain->verification_token_hash, hash('sha256', $token));
        if (! $valid) {
            throw ValidationException::withMessages(['verification_token' => ['Custom-domain verification failed.']]);
        }

        DB::table('smart_ordering_domains')
            ->where('id', $domain->id)
            ->update([
                'status' => 'verified',
                'enabled' => true,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        $this->audit->record(
            'smart_ordering.custom_domain.verified',
            $actor,
            $site,
            ['store_id' => (int) $site->store_id, 'domain' => (string) $domain->domain, 'status' => (string) $domain->status],
            ['store_id' => (int) $site->store_id, 'domain' => (string) $domain->domain, 'status' => 'verified'],
            $request,
        );
    }

    public function revisionForSite(SmartOrderingSite $site, string $publicId): SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $site->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    public function snapshot(SmartOrderingSite $site): array
    {
        $entitlements = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->getKey())
            ->where('scope_key', 'site')
            ->pluck('enabled', 'module')
            ->map(static fn ($enabled): bool => (bool) $enabled)
            ->all();

        $published = $this->configuration->published($site);

        return [
            'site' => [
                'public_id' => (string) $site->public_id,
                'store_id' => (int) $site->store_id,
                'slug' => (string) $site->slug,
                'master_enabled' => (bool) $site->master_enabled,
                'runtime_state' => (string) $site->runtime_state,
            ],
            'entitlements' => $entitlements,
            'published_revision' => $published === null ? null : [
                'public_id' => (string) $published->public_id,
                'checksum' => (string) $published->checksum,
                'published_at' => $published->published_at?->toISOString(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function siteAuditPayload(SmartOrderingSite $site): array
    {
        return [
            'store_id' => (int) $site->store_id,
            'site_public_id' => (string) $site->public_id,
            'slug' => (string) $site->slug,
            'master_enabled' => (bool) $site->master_enabled,
            'runtime_state' => (string) $site->runtime_state,
        ];
    }
}
