<?php

namespace App\Services;

use App\Models\AppPreviewSession;
use App\Models\StorefrontRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StorefrontRevisionService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AppPreviewInvalidationService $invalidations,
    ) {}

    public function ensurePublished(int $storeId, string $channel, ?User $actor = null): StorefrontRevision
    {
        $this->assertStoreChannel($storeId, $channel);

        $published = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', 'published')
            ->latest('id')
            ->first();

        if ($published instanceof StorefrontRevision) {
            $this->archiveAssetsForRevision($published);

            return $published;
        }

        return DB::transaction(function () use ($storeId, $channel, $actor): StorefrontRevision {
            $locked = StorefrontRevision::query()
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where('status', 'published')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($locked instanceof StorefrontRevision) {
                return $locked;
            }

            $payload = $this->snapshotLive($storeId, $channel);

            return $this->createRevision(
                storeId: $storeId,
                channel: $channel,
                status: 'published',
                payload: $payload,
                actor: $actor,
                parentRevisionId: null,
                sourceRevisionId: null,
                published: true,
            );
        });
    }

    public function synchronizePublishedFromLive(
        User $actor,
        int $storeId,
        string $channel,
        ?Request $request = null,
    ): StorefrontRevision {
        $this->assertStoreChannel($storeId, $channel);

        return DB::transaction(function () use ($actor, $storeId, $channel, $request): StorefrontRevision {
            $current = StorefrontRevision::query()
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where('status', 'published')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $payload = $this->snapshotLive($storeId, $channel);
            $encoded = $this->encode($payload);
            $checksum = hash('sha256', $encoded);

            if ($current instanceof StorefrontRevision && hash_equals($current->checksum, $checksum)) {
                $this->archiveAssetsForRevision($current);

                return $current;
            }

            if ($current instanceof StorefrontRevision) {
                $current->forceFill(['status' => 'archived'])->save();
            }

            $revision = $this->createRevision(
                storeId: $storeId,
                channel: $channel,
                status: 'published',
                payload: $payload,
                actor: $actor,
                parentRevisionId: $current?->getKey(),
                sourceRevisionId: null,
                published: true,
            );

            $this->audit->record(
                'storefront.revision.live_sync_published',
                $actor,
                $revision,
                null,
                $this->auditPayload($revision),
                $request,
            );
            $this->invalidations->emitForRevision($revision);

            return $revision;
        });
    }

    public function createOrReuseDraft(
        User $actor,
        int $storeId,
        string $channel,
        ?Request $request = null,
    ): StorefrontRevision {
        $this->assertStoreChannel($storeId, $channel);
        $this->ensurePublished($storeId, $channel, $actor);

        return DB::transaction(function () use ($actor, $storeId, $channel, $request): StorefrontRevision {
            // The current Published row is the serialization point for this
            // storefront scope, preventing concurrent requests from creating
            // multiple active Draft revisions.
            $published = StorefrontRevision::query()
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where('status', 'published')
                ->lockForUpdate()
                ->latest('id')
                ->firstOrFail();

            $draft = StorefrontRevision::query()
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where('status', 'draft')
                ->latest('id')
                ->first();

            if ($draft instanceof StorefrontRevision) {
                return $draft;
            }

            $revision = $this->createRevision(
                storeId: $storeId,
                channel: $channel,
                status: 'draft',
                payload: $published->payload,
                actor: $actor,
                parentRevisionId: $published->getKey(),
                sourceRevisionId: null,
                published: false,
            );

            $this->audit->record(
                'storefront.revision.draft_created',
                $actor,
                $revision,
                null,
                $this->auditPayload($revision),
                $request,
            );
            $this->invalidations->emitForRevision(
                $revision,
                AppPreviewInvalidationService::STOREFRONT_UPDATED,
            );

            return $revision;
        });
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(
        User $actor,
        StorefrontRevision $revision,
        array $payload,
        ?Request $request = null,
    ): StorefrontRevision {
        abort_unless($revision->status === 'draft', 409, 'Published storefront revisions are immutable.');

        $normalized = $this->validatedPayload(
            $payload,
            (int) $revision->store_id,
            (string) $revision->channel,
        );
        $encoded = $this->encode($normalized);
        $beforeChecksum = (string) $revision->checksum;

        $newChecksum = hash('sha256', $encoded);
        $revision->forceFill([
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => $newChecksum,
            'created_by' => $actor->getKey(),
        ])->save();
        $this->archiveAssetsForRevision($revision);

        $this->audit->record(
            'storefront.revision.draft_updated',
            $actor,
            $revision,
            ['checksum' => $beforeChecksum],
            $this->auditPayload($revision),
            $request,
        );

        if (! hash_equals($beforeChecksum, $newChecksum)) {
            $this->invalidations->emitForRevision(
                $revision,
                AppPreviewInvalidationService::STOREFRONT_UPDATED,
            );
        }

        return $revision->fresh();
    }

    public function publish(
        User $actor,
        StorefrontRevision $revision,
        ?Request $request = null,
    ): StorefrontRevision {
        return DB::transaction(function () use ($actor, $revision, $request): StorefrontRevision {
            $draft = StorefrontRevision::query()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($draft->status === 'draft', 409, 'Only a Draft storefront revision can be published.');
            $this->assertSupportedSchema($draft);
            $this->validatedPayload($draft->payload, (int) $draft->store_id, (string) $draft->channel);

            $current = StorefrontRevision::query()
                ->where('store_id', $draft->store_id)
                ->where('channel', $draft->channel)
                ->where('status', 'published')
                ->whereKeyNot($draft->getKey())
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $this->applyPayloadToLive(
                $draft->payload,
                (int) $draft->store_id,
                (string) $draft->channel,
                $draft,
            );

            if ($current instanceof StorefrontRevision) {
                $current->forceFill(['status' => 'archived'])->save();
            }

            $draft->forceFill([
                'status' => 'published',
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ])->save();

            $this->audit->record(
                'storefront.revision.published',
                $actor,
                $draft,
                $current instanceof StorefrontRevision ? $this->auditPayload($current) : null,
                $this->auditPayload($draft),
                $request,
            );
            $this->invalidations->emitForRevision($draft);

            return $draft->fresh();
        });
    }

    public function rollback(
        User $actor,
        StorefrontRevision $source,
        ?Request $request = null,
    ): StorefrontRevision {
        abort_unless(in_array($source->status, ['published', 'archived'], true), 409);
        $this->assertSupportedSchema($source);
        $this->validatedPayload($source->payload, (int) $source->store_id, (string) $source->channel);

        return DB::transaction(function () use ($actor, $source, $request): StorefrontRevision {
            $current = StorefrontRevision::query()
                ->where('store_id', $source->store_id)
                ->where('channel', $source->channel)
                ->where('status', 'published')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $this->applyPayloadToLive(
                $source->payload,
                (int) $source->store_id,
                (string) $source->channel,
                $source,
            );

            if ($current instanceof StorefrontRevision) {
                $current->forceFill(['status' => 'archived'])->save();
            }

            $rolledBack = $this->createRevision(
                storeId: (int) $source->store_id,
                channel: (string) $source->channel,
                status: 'published',
                payload: $source->payload,
                actor: $actor,
                parentRevisionId: $current?->getKey(),
                sourceRevisionId: $source->getKey(),
                published: true,
            );

            $this->audit->record(
                'storefront.revision.rolled_back',
                $actor,
                $rolledBack,
                $current instanceof StorefrontRevision ? $this->auditPayload($current) : null,
                [
                    ...$this->auditPayload($rolledBack),
                    'rollback_source_revision_id' => $source->getKey(),
                    'rollback_source_public_id' => $source->public_id,
                ],
                $request,
            );
            $this->invalidations->emitForRevision($rolledBack);

            return $rolledBack;
        });
    }

    public function resolvePublished(int $storeId, string $channel): StorefrontRevision
    {
        $this->assertStoreChannel($storeId, $channel);

        return StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', 'published')
            ->latest('id')
            ->firstOrFail();
    }

    public function resolveForPreview(
        AppPreviewSession $session,
        StorefrontRevision $revision,
    ): StorefrontRevision {
        abort_unless($session->revoked_at === null, 401);
        abort_unless($session->target_type === 'customer', 404);
        abort_unless((string) $session->mode === 'read_only', 403);
        abort_unless((int) $session->store_id === (int) $revision->store_id, 404);
        abort_unless((string) $session->channel === (string) $revision->channel, 404);
        abort_unless(in_array($revision->status, ['draft', 'published'], true), 404);
        $this->assertSupportedSchema($revision);

        return $revision;
    }

    public function resolveCurrent(
        int $storeId,
        string $channel,
        string $mode,
    ): StorefrontRevision {
        $this->assertStoreChannel($storeId, $channel);
        abort_unless(in_array($mode, ['draft', 'published'], true), 422);

        $revision = $this->findCurrent($storeId, $channel, $mode);

        abort_unless(
            $revision instanceof StorefrontRevision,
            404,
            $mode === 'draft'
                ? 'No Draft storefront revision exists for this preview scope.'
                : 'No Published storefront revision exists for this preview scope.',
        );

        return $revision;
    }

    public function findCurrent(
        int $storeId,
        string $channel,
        string $mode,
    ): ?StorefrontRevision {
        $this->assertStoreChannel($storeId, $channel);
        abort_unless(in_array($mode, ['draft', 'published'], true), 422);

        $revision = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', $mode)
            ->latest('id')
            ->first();

        if (! $revision instanceof StorefrontRevision) {
            return null;
        }

        $this->assertSupportedSchema($revision);

        return $revision;
    }

    public function resolveCurrentForPreview(
        AppPreviewSession $session,
        string $mode,
    ): StorefrontRevision {
        abort_unless($session->target_type === 'customer', 404);
        abort_unless((string) $session->mode === 'read_only', 403);

        return $this->resolveForPreview(
            $session,
            $this->resolveCurrent(
                (int) $session->store_id,
                (string) $session->channel,
                $mode,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function snapshotLive(int $storeId, string $channel): array
    {
        $this->assertStoreChannel($storeId, $channel);

        $store = DB::table('stores')->where('id', $storeId)->first();
        abort_unless($store !== null, 404);

        $settings = DB::table('storefront_settings')->where('store_id', $storeId)->first();

        $sections = DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $section): array => [
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title_ar' => $section->title_ar,
                'title_en' => $section->title_en,
                'sort_order' => (int) $section->sort_order,
                'config' => $this->decodeJson($section->config ?? null),
                'is_active' => (bool) $section->is_active,
            ])
            ->values()
            ->all();

        $serviceZones = DB::table('store_service_zones')
            ->where('store_id', $storeId)
            ->orderBy('country_code')
            ->orderBy('city')
            ->orderBy('area')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $zone): array => [
                'country_code' => (string) $zone->country_code,
                'city' => $zone->city,
                'area' => $zone->area,
                'is_active' => (bool) $zone->is_active,
            ])
            ->values()
            ->all();

        $banners = DB::table('banners')
            ->where('store_id', $storeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $banner): array => [
                'title' => (string) $banner->title,
                'image_path' => (string) $banner->image_path,
                'target_type' => property_exists($banner, 'target_type') ? $banner->target_type : null,
                'target_id' => property_exists($banner, 'target_id') && $banner->target_id !== null
                    ? (int) $banner->target_id
                    : null,
                'target_url' => $banner->target_url ?? null,
                'sort_order' => (int) $banner->sort_order,
                'is_active' => (bool) $banner->is_active,
                'starts_at' => $banner->starts_at ?? null,
                'ends_at' => $banner->ends_at ?? null,
            ])
            ->values()
            ->all();

        return $this->canonicalize([
            'schema_version' => self::SCHEMA_VERSION,
            'channel' => $channel,
            'store' => [
                'id' => $storeId,
                'code' => (string) ($store->code ?? ''),
                'name' => (string) ($store->name ?? ''),
                'logo_path' => $store->logo_path ?? null,
                'is_active' => (bool) ($store->is_active ?? false),
            ],
            'settings' => [
                'theme_code' => $settings->theme_code ?? ($channel === 'b2b' ? 'wholesale_b2b' : 'retail_grocery'),
                'primary_color' => $settings->primary_color ?? null,
                'primary_dark_color' => $settings->primary_dark_color ?? null,
                'accent_color' => $settings->accent_color ?? null,
                'background_color' => $settings->background_color ?? null,
                'header_address' => $settings->header_address ?? null,
                'branding' => $this->decodeJson($settings->branding ?? null),
            ],
            'sections' => $sections,
            'banners' => $banners,
            'service_zones' => $serviceZones,
        ]);
    }

    public function preserveLiveAssetsForScope(int $storeId, string $channel): void
    {
        $this->assertStoreChannel($storeId, $channel);
        $this->ensurePublished($storeId, $channel);

        StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->orderBy('id')
            ->get()
            ->each(fn (StorefrontRevision $revision) => $this->archiveAssetsForRevision($revision));
    }

    /** @param array<string,mixed> $payload */
    private function applyPayloadToLive(
        array $payload,
        int $storeId,
        string $channel,
        ?StorefrontRevision $assetRevision = null,
    ): void {
        $normalized = $this->validatedPayload($payload, $storeId, $channel);

        $settings = (array) $normalized['settings'];
        DB::table('storefront_settings')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'theme_code' => (string) ($settings['theme_code'] ?? ($channel === 'b2b' ? 'wholesale_b2b' : 'retail_grocery')),
                'primary_color' => $settings['primary_color'] ?? null,
                'primary_dark_color' => $settings['primary_dark_color'] ?? null,
                'accent_color' => $settings['accent_color'] ?? null,
                'background_color' => $settings['background_color'] ?? null,
                'header_address' => $settings['header_address'] ?? null,
                'branding' => ($settings['branding'] ?? []) === []
                    ? null
                    : json_encode($settings['branding'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $store = (array) $normalized['store'];
        DB::table('stores')->where('id', $storeId)->update([
            'logo_path' => $this->restorableAssetPath(
                $assetRevision,
                isset($store['logo_path']) ? (string) $store['logo_path'] : null,
            ),
            'updated_at' => now(),
        ]);

        DB::table('storefront_sections')->where('store_id', $storeId)->delete();
        foreach ((array) $normalized['sections'] as $section) {
            if (! is_array($section)) {
                continue;
            }

            DB::table('storefront_sections')->insert([
                'store_id' => $storeId,
                'section_key' => (string) ($section['key'] ?? ''),
                'section_type' => (string) ($section['type'] ?? ''),
                'title_ar' => $section['title_ar'] ?? null,
                'title_en' => $section['title_en'] ?? null,
                'sort_order' => (int) ($section['sort_order'] ?? 0),
                'config' => ($section['config'] ?? []) === []
                    ? null
                    : json_encode($section['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => (bool) ($section['is_active'] ?? false),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('banners')->where('store_id', $storeId)->delete();
        foreach ((array) $normalized['banners'] as $banner) {
            if (! is_array($banner)) {
                continue;
            }

            DB::table('banners')->insert([
                'store_id' => $storeId,
                'title' => (string) ($banner['title'] ?? ''),
                'image_path' => (string) $this->restorableAssetPath(
                    $assetRevision,
                    (string) ($banner['image_path'] ?? ''),
                ),
                'target_type' => $banner['target_type'] ?? null,
                'target_id' => $banner['target_id'] ?? null,
                'target_url' => $banner['target_url'] ?? null,
                'sort_order' => (int) ($banner['sort_order'] ?? 0),
                'is_active' => (bool) ($banner['is_active'] ?? false),
                'starts_at' => $banner['starts_at'] ?? null,
                'ends_at' => $banner['ends_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($channel === 'b2c') {
            DB::table('store_service_zones')->where('store_id', $storeId)->delete();
            foreach ((array) $normalized['service_zones'] as $zone) {
                if (! is_array($zone)) {
                    continue;
                }

                DB::table('store_service_zones')->insert([
                    'store_id' => $storeId,
                    'country_code' => strtoupper((string) ($zone['country_code'] ?? '')),
                    'city' => $zone['city'] ?? null,
                    'area' => $zone['area'] ?? null,
                    'is_active' => (bool) ($zone['is_active'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return array<string,mixed> */
    public function metadata(StorefrontRevision $revision): array
    {
        return [
            'id' => (int) $revision->getKey(),
            'revision_id' => (string) $revision->public_id,
            'store_id' => (int) $revision->store_id,
            'channel' => (string) $revision->channel,
            'status' => (string) $revision->status,
            'schema_version' => (int) $revision->schema_version,
            'checksum' => (string) $revision->checksum,
            'parent_revision_id' => $revision->parent_revision_id,
            'source_revision_id' => $revision->source_revision_id,
            'created_by' => $revision->created_by,
            'published_by' => $revision->published_by,
            'published_at' => $revision->published_at?->toIso8601String(),
            'created_at' => $revision->created_at?->toIso8601String(),
            'updated_at' => $revision->updated_at?->toIso8601String(),
        ];
    }

    private function createRevision(
        int $storeId,
        string $channel,
        string $status,
        array $payload,
        ?User $actor,
        ?int $parentRevisionId,
        ?int $sourceRevisionId,
        bool $published,
    ): StorefrontRevision {
        $normalized = $this->validatedPayload($payload, $storeId, $channel);
        $encoded = $this->encode($normalized);

        $revision = StorefrontRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'channel' => $channel,
            'status' => $status,
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => hash('sha256', $encoded),
            'parent_revision_id' => $parentRevisionId,
            'source_revision_id' => $sourceRevisionId,
            'created_by' => $actor?->getKey(),
            'published_by' => $published ? $actor?->getKey() : null,
            'published_at' => $published ? now() : null,
        ]);

        $this->archiveAssetsForRevision($revision);

        return $revision;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validatedPayload(array $payload, int $storeId, string $channel): array
    {
        $schemaVersion = (int) ($payload['schema_version'] ?? 0);
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw ValidationException::withMessages([
                'schema_version' => ['Unsupported storefront schema version.'],
            ]);
        }

        if (($payload['channel'] ?? null) !== $channel
            || (int) data_get($payload, 'store.id', 0) !== $storeId) {
            throw ValidationException::withMessages([
                'payload' => ['Storefront revision scope does not match the requested store/channel.'],
            ]);
        }

        foreach (['store', 'settings', 'sections', 'banners', 'service_zones'] as $key) {
            if (! array_key_exists($key, $payload) || ! is_array($payload[$key])) {
                throw ValidationException::withMessages([
                    'payload' => ["Storefront revision payload is missing {$key}."],
                ]);
            }
        }

        $settings = (array) $payload['settings'];
        if (! is_string($settings['theme_code'] ?? null) || trim((string) $settings['theme_code']) === '') {
            throw ValidationException::withMessages([
                'payload.settings.theme_code' => ['Storefront theme code is required.'],
            ]);
        }
        if (isset($settings['branding']) && ! is_array($settings['branding'])) {
            throw ValidationException::withMessages([
                'payload.settings.branding' => ['Storefront branding must be an object.'],
            ]);
        }

        $sectionKeys = [];
        foreach ((array) $payload['sections'] as $index => $section) {
            if (! is_array($section)) {
                throw ValidationException::withMessages([
                    "payload.sections.{$index}" => ['Storefront section must be an object.'],
                ]);
            }

            $key = trim((string) ($section['key'] ?? ''));
            $type = trim((string) ($section['type'] ?? ''));
            if ($key === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1 || $type === '') {
                throw ValidationException::withMessages([
                    "payload.sections.{$index}" => ['Storefront section key/type is invalid.'],
                ]);
            }
            if (isset($sectionKeys[$key])) {
                throw ValidationException::withMessages([
                    "payload.sections.{$index}.key" => ['Storefront section keys must be unique.'],
                ]);
            }
            $sectionKeys[$key] = true;

            if (isset($section['config']) && ! is_array($section['config'])) {
                throw ValidationException::withMessages([
                    "payload.sections.{$index}.config" => ['Storefront section config must be an object.'],
                ]);
            }
        }

        foreach ((array) $payload['banners'] as $index => $banner) {
            if (! is_array($banner)
                || trim((string) ($banner['title'] ?? '')) === ''
                || trim((string) ($banner['image_path'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "payload.banners.{$index}" => ['Storefront banner title and image path are required.'],
                ]);
            }

            $targetType = $banner['target_type'] ?? null;
            $allowedTargetTypes = $channel === 'b2b'
                ? ['product', 'category', 'retail_store']
                : ['product', 'category'];
            if ($targetType !== null && ! in_array($targetType, $allowedTargetTypes, true)) {
                throw ValidationException::withMessages([
                    "payload.banners.{$index}.target_type" => ['Storefront banner target type is invalid.'],
                ]);
            }

            $startsAt = $banner['starts_at'] ?? null;
            $endsAt = $banner['ends_at'] ?? null;
            $startsTimestamp = $startsAt === null ? null : strtotime((string) $startsAt);
            $endsTimestamp = $endsAt === null ? null : strtotime((string) $endsAt);
            if (($startsAt !== null && $startsTimestamp === false)
                || ($endsAt !== null && $endsTimestamp === false)
                || ($startsTimestamp !== null && $endsTimestamp !== null && $endsTimestamp < $startsTimestamp)) {
                throw ValidationException::withMessages([
                    "payload.banners.{$index}.schedule" => ['Storefront banner schedule window is invalid.'],
                ]);
            }
        }

        foreach ((array) $payload['service_zones'] as $index => $zone) {
            if (! is_array($zone)) {
                throw ValidationException::withMessages([
                    "payload.service_zones.{$index}" => ['Service zone must be an object.'],
                ]);
            }

            $countryCode = strtoupper(trim((string) ($zone['country_code'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                throw ValidationException::withMessages([
                    "payload.service_zones.{$index}.country_code" => ['Service-zone country code must contain two letters.'],
                ]);
            }
        }

        return $this->canonicalize($payload);
    }

    private function assertSupportedSchema(StorefrontRevision $revision): void
    {
        if ((int) $revision->schema_version !== self::SCHEMA_VERSION) {
            throw ValidationException::withMessages([
                'schema_version' => ['This storefront revision is not compatible with the current runtime.'],
            ]);
        }
    }

    private function assertStoreChannel(int $storeId, string $channel): void
    {
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422);

        $expectedType = $channel === 'b2b' ? 'B2B' : 'B2C';
        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('store_types.code', $expectedType)
            ->exists();

        abort_unless($exists, 404);
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function archiveAssetsForRevision(StorefrontRevision $revision): void
    {
        $payload = $revision->payload;
        $assets = [];

        $logoPath = data_get($payload, 'store.logo_path');
        if (is_string($logoPath) && trim($logoPath) !== '') {
            $assets[] = ['type' => 'logo', 'path' => $logoPath];
        }

        foreach ((array) ($payload['banners'] ?? []) as $banner) {
            if (! is_array($banner)) {
                continue;
            }
            $path = $banner['image_path'] ?? null;
            if (is_string($path) && trim($path) !== '') {
                $assets[] = ['type' => 'banner', 'path' => $path];
            }
        }

        foreach ($assets as $asset) {
            $archived = $this->archiveAssetPath((int) $revision->store_id, (string) $asset['path']);
            if ($archived === null) {
                continue;
            }

            DB::table('storefront_revision_assets')->updateOrInsert(
                [
                    'storefront_revision_id' => $revision->getKey(),
                    'source_path_hash' => hash('sha256', (string) $asset['path']),
                ],
                [
                    'asset_type' => (string) $asset['type'],
                    'source_path' => (string) $asset['path'],
                    'archive_path' => $archived['path'],
                    'content_hash' => $archived['hash'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @return array{path:string,hash:string}|null */
    private function archiveAssetPath(int $storeId, string $path): ?array
    {
        $normalized = ltrim(trim($path), '/');
        if (! str_starts_with($normalized, 'storage/')) {
            return null;
        }

        $relative = substr($normalized, strlen('storage/'));
        $disk = Storage::disk('public');
        if (! $disk->exists($relative)) {
            return null;
        }

        $contents = $disk->get($relative);
        $hash = hash('sha256', $contents);
        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        $filename = $hash.($extension === '' ? '' : '.'.$extension);
        $archiveRelative = 'storefront-revision-assets/'.$storeId.'/'.$filename;

        if (! $disk->exists($archiveRelative)) {
            $disk->put($archiveRelative, $contents, ['visibility' => 'public']);
        }

        return [
            'path' => 'storage/'.$archiveRelative,
            'hash' => $hash,
        ];
    }

    private function restorableAssetPath(
        ?StorefrontRevision $revision,
        ?string $sourcePath,
    ): ?string {
        if ($sourcePath === null || trim($sourcePath) === '') {
            return null;
        }

        $normalized = ltrim(trim($sourcePath), '/');
        if (! str_starts_with($normalized, 'storage/')) {
            return $sourcePath;
        }

        $relative = substr($normalized, strlen('storage/'));
        $disk = Storage::disk('public');
        if ($disk->exists($relative)) {
            return $sourcePath;
        }

        $candidate = $revision;
        while ($candidate instanceof StorefrontRevision) {
            $asset = DB::table('storefront_revision_assets')
                ->where('storefront_revision_id', $candidate->getKey())
                ->where('source_path_hash', hash('sha256', $sourcePath))
                ->first(['archive_path']);

            if ($asset !== null && is_string($asset->archive_path)) {
                $archive = ltrim($asset->archive_path, '/');
                if (str_starts_with($archive, 'storage/')
                    && $disk->exists(substr($archive, strlen('storage/')))) {
                    return $asset->archive_path;
                }
            }

            $candidate = $candidate->parent_revision_id === null
                ? null
                : StorefrontRevision::query()->find($candidate->parent_revision_id);
        }

        throw ValidationException::withMessages([
            'asset' => ['A storefront revision asset is no longer available for publish or rollback.'],
        ]);
    }

    /** @return array<string,mixed> */
    private function auditPayload(StorefrontRevision $revision): array
    {
        return [
            'revision_id' => (string) $revision->public_id,
            'store_id' => (int) $revision->store_id,
            'channel' => (string) $revision->channel,
            'status' => (string) $revision->status,
            'schema_version' => (int) $revision->schema_version,
            'checksum' => (string) $revision->checksum,
            'parent_revision_id' => $revision->parent_revision_id,
            'source_revision_id' => $revision->source_revision_id,
        ];
    }
}
