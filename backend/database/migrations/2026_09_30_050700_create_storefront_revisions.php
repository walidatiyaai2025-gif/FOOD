<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SCHEMA_VERSION = 1;

    public function up(): void
    {
        Schema::create('storefront_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('channel', 8);
            $table->string('status', 16);
            $table->unsignedInteger('schema_version')->default(self::SCHEMA_VERSION);
            $table->longText('payload');
            $table->char('checksum', 64);
            $table->foreignId('parent_revision_id')->nullable()
                ->constrained('storefront_revisions')->nullOnDelete();
            $table->foreignId('source_revision_id')->nullable()
                ->constrained('storefront_revisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'channel', 'status'], 'storefront_revision_scope_status_idx');
            $table->index(['store_id', 'channel', 'created_at'], 'storefront_revision_scope_created_idx');
            $table->index(['checksum', 'schema_version'], 'storefront_revision_checksum_idx');
        });

        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('store_types.code', ['B2B', 'B2C'])
            ->orderBy('stores.id')
            ->get(['stores.id', 'store_types.code']);

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            $channel = strtoupper((string) $store->code) === 'B2B' ? 'b2b' : 'b2c';

            if (DB::table('storefront_revisions')
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where('status', 'published')
                ->exists()) {
                continue;
            }

            $payload = $this->snapshot($storeId, $channel);
            $encoded = $this->encode($payload);

            DB::table('storefront_revisions')->insert([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'channel' => $channel,
                'status' => 'published',
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => $encoded,
                'checksum' => hash('sha256', $encoded),
                'parent_revision_id' => null,
                'source_revision_id' => null,
                'created_by' => null,
                'published_by' => null,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_revisions');
    }

    /** @return array<string,mixed> */
    private function snapshot(int $storeId, string $channel): array
    {
        $store = DB::table('stores')->where('id', $storeId)->first();
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
};
