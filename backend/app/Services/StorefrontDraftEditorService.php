<?php

namespace App\Services;

use App\Models\StorefrontRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StorefrontDraftEditorService
{
    public function __construct(
        private readonly StorefrontRevisionService $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{revision:StorefrontRevision,payload:array<string,mixed>,has_draft:bool} */
    public function editorState(int $storeId, string $channel): array
    {
        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', 'draft')
            ->latest('id')
            ->first();

        $revision = $draft instanceof StorefrontRevision
            ? $draft
            : $this->revisions->ensurePublished($storeId, $channel);

        return [
            'revision' => $revision,
            'payload' => $this->withEditorIds($revision->payload),
            'has_draft' => $draft instanceof StorefrontRevision,
        ];
    }

    /** @return array<string,mixed> */
    public function viewModel(int $storeId, string $channel): array
    {
        $state = $this->editorState($storeId, $channel);
        $revision = $state['revision'];
        $payload = $state['payload'];
        $settings = (array) ($payload['settings'] ?? []);
        $branding = (array) ($settings['branding'] ?? []);
        $store = (array) ($payload['store'] ?? []);

        return [
            'revision' => [
                ...$this->revisions->metadata($revision),
                'has_draft' => (bool) $state['has_draft'],
            ],
            'store' => [
                'id' => $storeId,
                'code' => (string) ($store['code'] ?? ''),
                'name' => (string) ($store['name'] ?? ''),
                'logo_path' => $store['logo_path'] ?? null,
                'is_active' => (bool) ($store['is_active'] ?? false),
            ],
            'settings' => [
                'theme_code' => (string) ($settings['theme_code'] ?? ($channel === 'b2b' ? 'wholesale_b2b' : 'retail_grocery')),
                'primary_color' => $settings['primary_color'] ?? null,
                'primary_dark_color' => $settings['primary_dark_color'] ?? null,
                'accent_color' => $settings['accent_color'] ?? null,
                'background_color' => $settings['background_color'] ?? null,
                'header_address' => $settings['header_address'] ?? '',
                'brand_title_ar' => (string) ($branding['brand_title_ar'] ?? ''),
                'brand_title_en' => (string) ($branding['brand_title_en'] ?? ''),
                'brand_subtitle_ar' => (string) ($branding['brand_subtitle_ar'] ?? ''),
                'brand_subtitle_en' => (string) ($branding['brand_subtitle_en'] ?? ''),
                'hero_cta_ar' => (string) ($branding['hero_cta_ar'] ?? ''),
                'hero_cta_en' => (string) ($branding['hero_cta_en'] ?? ''),
            ],
            'sections' => collect((array) ($payload['sections'] ?? []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'id' => (string) $item['editor_id'],
                    'section_key' => (string) ($item['key'] ?? ''),
                    'section_type' => (string) ($item['type'] ?? ''),
                    'title_ar' => (string) ($item['title_ar'] ?? ''),
                    'title_en' => (string) ($item['title_en'] ?? ''),
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'config_json' => ($item['config'] ?? []) === []
                        ? ''
                        : json_encode($item['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_active' => (bool) ($item['is_active'] ?? false),
                ])
                ->sortBy('sort_order')
                ->values()
                ->all(),
            'zones' => collect((array) ($payload['service_zones'] ?? []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'id' => (string) $item['editor_id'],
                    'country_code' => (string) ($item['country_code'] ?? ''),
                    'city' => (string) ($item['city'] ?? ''),
                    'area' => (string) ($item['area'] ?? ''),
                    'is_active' => (bool) ($item['is_active'] ?? false),
                ])
                ->values()
                ->all(),
            'banners' => collect((array) ($payload['banners'] ?? []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'id' => (string) $item['editor_id'],
                    '_id' => (string) $item['editor_id'],
                    '_store_id' => $storeId,
                    'title' => (string) ($item['title'] ?? ''),
                    'image' => (string) ($item['image_path'] ?? ''),
                    'target_ref' => ($item['target_type'] ?? null) !== null && ($item['target_id'] ?? null) !== null
                        ? (string) $item['target_type'].':'.(int) $item['target_id']
                        : '',
                    '_target_ref' => ($item['target_type'] ?? null) !== null && ($item['target_id'] ?? null) !== null
                        ? (string) $item['target_type'].':'.(int) $item['target_id']
                        : '',
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'is_active' => (bool) ($item['is_active'] ?? false),
                    'status' => (bool) ($item['is_active'] ?? false),
                ])
                ->sortBy('sort_order')
                ->values()
                ->all(),
        ];
    }

    /** @param array<string,mixed> $settings */
    public function updateSettings(
        User $actor,
        int $storeId,
        string $channel,
        array $settings,
        ?string $logoPath,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);

        $payloadSettings = (array) ($payload['settings'] ?? []);
        $branding = (array) ($payloadSettings['branding'] ?? []);
        $incomingBranding = (array) ($settings['branding'] ?? []);

        foreach (['theme_code', 'primary_color', 'primary_dark_color', 'accent_color', 'background_color', 'header_address'] as $key) {
            if (array_key_exists($key, $settings)) {
                $payloadSettings[$key] = $settings[$key];
            }
        }

        foreach ($incomingBranding as $key => $value) {
            $branding[(string) $key] = $value;
        }
        $payloadSettings['branding'] = $branding;
        $payload['settings'] = $payloadSettings;

        if ($logoPath !== null) {
            $store = (array) ($payload['store'] ?? []);
            $store['logo_path'] = $logoPath;
            $payload['store'] = $store;
        }

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @param array<string,mixed> $section */
    public function addSection(
        User $actor,
        int $storeId,
        string $channel,
        array $section,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $sections = array_values((array) ($payload['sections'] ?? []));
        $key = trim((string) ($section['key'] ?? ''));

        foreach ($sections as $existing) {
            if (is_array($existing) && (string) ($existing['key'] ?? '') === $key) {
                throw ValidationException::withMessages([
                    'section_key' => ['Section key already exists in this storefront Draft.'],
                ]);
            }
        }

        $sections[] = [
            'editor_id' => (string) Str::uuid(),
            'key' => $key,
            'type' => (string) ($section['type'] ?? ''),
            'title_ar' => $section['title_ar'] ?? null,
            'title_en' => $section['title_en'] ?? null,
            'sort_order' => (int) ($section['sort_order'] ?? 0),
            'config' => is_array($section['config'] ?? null) ? $section['config'] : [],
            'is_active' => (bool) ($section['is_active'] ?? false),
        ];
        $payload['sections'] = $sections;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @param array<string,mixed> $section */
    public function updateSection(
        User $actor,
        int $storeId,
        string $channel,
        string $editorId,
        array $section,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $sections = array_values((array) ($payload['sections'] ?? []));
        $index = $this->findByEditorId($sections, $editorId);
        $key = trim((string) ($section['key'] ?? ''));

        foreach ($sections as $candidateIndex => $existing) {
            if ($candidateIndex !== $index
                && is_array($existing)
                && (string) ($existing['key'] ?? '') === $key) {
                throw ValidationException::withMessages([
                    'section_key' => ['Section key already exists in this storefront Draft.'],
                ]);
            }
        }

        $sections[$index] = [
            'editor_id' => $editorId,
            'key' => $key,
            'type' => (string) ($section['type'] ?? ''),
            'title_ar' => $section['title_ar'] ?? null,
            'title_en' => $section['title_en'] ?? null,
            'sort_order' => (int) ($section['sort_order'] ?? 0),
            'config' => is_array($section['config'] ?? null) ? $section['config'] : [],
            'is_active' => (bool) ($section['is_active'] ?? false),
        ];
        $payload['sections'] = $sections;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    public function removeSection(
        User $actor,
        int $storeId,
        string $channel,
        string $editorId,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $sections = array_values((array) ($payload['sections'] ?? []));
        $index = $this->findByEditorId($sections, $editorId);
        array_splice($sections, $index, 1);
        $payload['sections'] = $sections;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @param array<string,mixed> $zone */
    public function addZone(
        User $actor,
        int $storeId,
        array $zone,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, 'b2c', $request);
        $zones = array_values((array) ($payload['service_zones'] ?? []));
        $normalized = [
            'editor_id' => (string) Str::uuid(),
            'country_code' => strtoupper(trim((string) ($zone['country_code'] ?? ''))),
            'city' => $this->nullableString($zone['city'] ?? null),
            'area' => $this->nullableString($zone['area'] ?? null),
            'is_active' => (bool) ($zone['is_active'] ?? false),
        ];

        foreach ($zones as $existing) {
            if (! is_array($existing)) {
                continue;
            }
            if (strtoupper((string) ($existing['country_code'] ?? '')) === $normalized['country_code']
                && $this->nullableString($existing['city'] ?? null) === $normalized['city']
                && $this->nullableString($existing['area'] ?? null) === $normalized['area']) {
                $existing['is_active'] = $normalized['is_active'];
                $existing['editor_id'] = (string) ($existing['editor_id'] ?? $this->derivedEditorId('zone', $existing, 0));
                $zones[$this->findMatchingZoneIndex($zones, $normalized)] = $existing;
                $payload['service_zones'] = $zones;

                return $this->revisions->updateDraft($actor, $draft, $payload, $request);
            }
        }

        $zones[] = $normalized;
        $payload['service_zones'] = $zones;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    public function removeZone(
        User $actor,
        int $storeId,
        string $editorId,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, 'b2c', $request);
        $zones = array_values((array) ($payload['service_zones'] ?? []));
        $index = $this->findByEditorId($zones, $editorId);
        array_splice($zones, $index, 1);
        $payload['service_zones'] = $zones;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @param array<string,mixed> $banner */
    public function addBanner(
        User $actor,
        int $storeId,
        string $channel,
        array $banner,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $banners = array_values((array) ($payload['banners'] ?? []));
        $this->assertTargetBelongsToStore($storeId, $channel, $banner['target_type'] ?? null, $banner['target_id'] ?? null);

        $banners[] = [
            'editor_id' => (string) Str::uuid(),
            'title' => trim((string) ($banner['title'] ?? '')),
            'image_path' => (string) ($banner['image_path'] ?? ''),
            'target_type' => $banner['target_type'] ?? null,
            'target_id' => $banner['target_id'] ?? null,
            'target_url' => $banner['target_url'] ?? null,
            'sort_order' => (int) ($banner['sort_order'] ?? 0),
            'is_active' => (bool) ($banner['is_active'] ?? false),
        ];
        $payload['banners'] = $banners;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @param array<string,mixed> $banner */
    public function updateBanner(
        User $actor,
        int $storeId,
        string $channel,
        string $editorId,
        array $banner,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $banners = array_values((array) ($payload['banners'] ?? []));
        $index = $this->findByEditorId($banners, $editorId);
        $current = is_array($banners[$index] ?? null) ? $banners[$index] : [];

        $this->assertTargetBelongsToStore($storeId, $channel, $banner['target_type'] ?? null, $banner['target_id'] ?? null);

        $banners[$index] = [
            'editor_id' => $editorId,
            'title' => trim((string) ($banner['title'] ?? $current['title'] ?? '')),
            'image_path' => (string) ($banner['image_path'] ?? $current['image_path'] ?? ''),
            'target_type' => $banner['target_type'] ?? null,
            'target_id' => $banner['target_id'] ?? null,
            'target_url' => $banner['target_url'] ?? null,
            'sort_order' => (int) ($banner['sort_order'] ?? $current['sort_order'] ?? 0),
            'is_active' => (bool) ($banner['is_active'] ?? false),
        ];
        $payload['banners'] = $banners;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    public function removeBanner(
        User $actor,
        int $storeId,
        string $channel,
        string $editorId,
        ?Request $request = null,
    ): StorefrontRevision {
        [$draft, $payload] = $this->draftPayload($actor, $storeId, $channel, $request);
        $banners = array_values((array) ($payload['banners'] ?? []));
        $index = $this->findByEditorId($banners, $editorId);
        array_splice($banners, $index, 1);
        $payload['banners'] = $banners;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    public function publish(
        User $actor,
        int $storeId,
        string $channel,
        ?Request $request = null,
    ): StorefrontRevision {
        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        return $this->revisions->publish($actor, $draft, $request);
    }

    public function discard(
        User $actor,
        int $storeId,
        string $channel,
        ?Request $request = null,
    ): StorefrontRevision {
        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();
        $published = $this->revisions->resolveCurrent($storeId, $channel, 'published');

        $before = $this->revisions->metadata($draft);
        $reset = $this->revisions->updateDraft(
            $actor,
            $draft,
            $this->withEditorIds($published->payload),
            $request,
        );

        $this->audit->record(
            'storefront.revision.draft_discarded',
            $actor,
            $reset,
            $before,
            $this->revisions->metadata($reset),
            $request,
        );

        return $reset;
    }

    /** @return array{0:StorefrontRevision,1:array<string,mixed>} */
    private function draftPayload(
        User $actor,
        int $storeId,
        string $channel,
        ?Request $request,
    ): array {
        $draft = $this->revisions->createOrReuseDraft($actor, $storeId, $channel, $request);

        return [$draft, $this->withEditorIds($draft->payload)];
    }

    /** @return array<string,mixed> */
    public function withEditorIds(array $payload): array
    {
        foreach (['sections' => 'section', 'banners' => 'banner', 'service_zones' => 'zone'] as $key => $kind) {
            $items = array_values((array) ($payload[$key] ?? []));
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (! is_string($item['editor_id'] ?? null) || trim((string) $item['editor_id']) === '') {
                    $item['editor_id'] = $this->derivedEditorId($kind, $item, $index);
                }
                $items[$index] = $item;
            }
            $payload[$key] = $items;
        }

        return $payload;
    }

    /** @param list<mixed> $items */
    private function findByEditorId(array $items, string $editorId): int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && hash_equals((string) ($item['editor_id'] ?? ''), $editorId)) {
                return $index;
            }
        }

        abort(404, 'Storefront Draft item not found.');
    }

    /** @param list<mixed> $zones
     *  @param array<string,mixed> $target
     */
    private function findMatchingZoneIndex(array $zones, array $target): int
    {
        foreach ($zones as $index => $existing) {
            if (! is_array($existing)) {
                continue;
            }
            if (strtoupper((string) ($existing['country_code'] ?? '')) === (string) $target['country_code']
                && $this->nullableString($existing['city'] ?? null) === $target['city']
                && $this->nullableString($existing['area'] ?? null) === $target['area']) {
                return $index;
            }
        }

        abort(404);
    }

    /** @param array<string,mixed> $item */
    private function derivedEditorId(string $kind, array $item, int $index): string
    {
        unset($item['editor_id']);

        return $kind.'-'.substr(hash(
            'sha256',
            json_encode([$index, $item], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ), 0, 24);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertTargetBelongsToStore(
        int $storeId,
        string $channel,
        mixed $targetType,
        mixed $targetId,
    ): void {
        if ($targetType === null || $targetId === null) {
            return;
        }

        abort_unless(in_array($targetType, ['product', 'category'], true), 422);
        $table = $targetType === 'product' ? 'products' : 'categories';
        $exists = DB::table($table)
            ->join('catalogs', 'catalogs.id', '=', $table.'.catalog_id')
            ->where($table.'.id', (int) $targetId)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', $channel)
            ->where('catalogs.is_migration_quarantine', false)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'target_ref' => ['Selected product or category does not belong to this storefront scope.'],
            ]);
        }
    }
}
