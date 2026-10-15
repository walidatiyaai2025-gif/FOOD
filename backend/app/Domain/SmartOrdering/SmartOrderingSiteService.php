<?php

namespace App\Domain\SmartOrdering;

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

    public function ensureForStore(int $storeId, ?User $actor = null): SmartOrderingSite
    {
        $existing = SmartOrderingSite::query()->where('store_id', $storeId)->first();
        if ($existing instanceof SmartOrderingSite) {
            return $existing;
        }

        return DB::transaction(function () use ($storeId, $actor): SmartOrderingSite {
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

            $slug = $this->availableSlug((string) ($store->code ?: $store->name), $storeId);
            $site = SmartOrderingSite::query()->create([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => $slug,
                'runtime_state' => 'DISABLED',
                'master_enabled' => false,
                'created_by' => $actor?->getKey(),
            ]);

            $this->replaceEntitlements($site, []);
            app(SmartOrderingRevisionService::class)->ensurePublished($site, $actor);

            return $site->fresh();
        });
    }

    public function updateRuntime(
        User $actor,
        SmartOrderingSite $site,
        string $runtimeState,
        bool $masterEnabled,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->assertManage($actor, $site);
        abort_unless(in_array($runtimeState, (array) config('smart_ordering.runtime_states', []), true), 422);

        $before = ['runtime_state' => $site->runtime_state, 'master_enabled' => (bool) $site->master_enabled];

        $site->forceFill([
            'runtime_state' => $runtimeState,
            'master_enabled' => $masterEnabled,
        ])->save();

        $this->audit->record('smart_ordering.runtime.updated', $actor, $site, $before, [
            'runtime_state' => $runtimeState,
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

        $modules = array_values(array_filter((array) config('smart_ordering.entitlements', []), 'is_string'));
        $before = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->id)
            ->pluck('enabled', 'module')
            ->map(fn ($enabled): bool => (bool) $enabled)
            ->all();

        $now = now();
        foreach ($modules as $module) {
            DB::table('smart_ordering_entitlements')->updateOrInsert(
                ['site_id' => $site->id, 'module' => $module],
                ['enabled' => (bool) ($values[$module] ?? false), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        if ($actor instanceof User) {
            $after = DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->id)
                ->pluck('enabled', 'module')
                ->map(fn ($enabled): bool => (bool) $enabled)
                ->all();
            $this->audit->record('smart_ordering.entitlements.updated', $actor, $site, $before, $after, $request);
        }
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

        $taken = SmartOrderingSite::query()->where('slug', $slug)->whereKeyNot($site->id)->exists()
            || DB::table('smart_ordering_site_aliases')->where('slug', $slug)->where('site_id', '!=', $site->id)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['slug' => __('smart_ordering.validation.slug_taken')]);
        }

        if ((string) $site->slug === $slug) {
            return $site;
        }

        $before = (string) $site->slug;
        DB::transaction(function () use ($site, $slug, $before): void {
            DB::table('smart_ordering_site_aliases')->updateOrInsert(
                ['slug' => $before],
                ['site_id' => $site->id, 'redirect_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            );
            $site->forceFill(['slug' => $slug])->save();
        });

        $this->audit->record('smart_ordering.slug.renamed', $actor, $site, ['slug' => $before], ['slug' => $slug], $request);

        return $site->fresh();
    }

    public function updateCustomDomain(
        User $actor,
        SmartOrderingSite $site,
        ?string $domain,
        ?Request $request = null,
    ): SmartOrderingSite {
        $this->assertManage($actor, $site);
        $domain = $domain === null ? null : strtolower(trim($domain));
        if ($domain === '') {
            $domain = null;
        }

        if ($domain !== null && (! filter_var('https://'.$domain, FILTER_VALIDATE_URL) || str_contains($domain, '/'))) {
            throw ValidationException::withMessages(['custom_domain' => __('smart_ordering.validation.domain')]);
        }

        $before = $site->only(['custom_domain', 'custom_domain_verified_at']);
        $site->forceFill([
            'custom_domain' => $domain,
            'custom_domain_verification_token' => $domain === null ? null : Str::random(48),
            'custom_domain_verified_at' => null,
        ])->save();

        $this->audit->record('smart_ordering.custom_domain.updated', $actor, $site, $before, [
            'custom_domain' => $domain,
            'custom_domain_verified_at' => null,
        ], $request);

        return $site->fresh();
    }

    private function availableSlug(string $source, int $storeId): string
    {
        $base = Str::slug($source);
        if ($base === '' || in_array($base, (array) config('smart_ordering.reserved_slugs', []), true)) {
            $base = 'restaurant-'.$storeId;
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
