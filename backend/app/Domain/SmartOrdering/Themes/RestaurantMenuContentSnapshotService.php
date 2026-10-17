<?php

namespace App\Domain\SmartOrdering\Themes;

use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingPriceResolver;
use App\Services\CommerceQuoteService;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RestaurantMenuContentSnapshotService
{
    public function __construct(
        private readonly SmartOrderingPriceResolver $prices,
        private readonly CommerceQuoteService $quotes,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(SmartOrderingSite $site, SmartOrderingRevision $revision): array
    {
        abort_unless((int) $revision->site_id === (int) $site->id, 404);

        $payload = (array) $revision->payload;
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $identity = is_array($content['identity'] ?? null) ? $content['identity'] : [];
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        $legacySite = is_array($payload['site'] ?? null) ? $payload['site'] : [];
        $store = $site->store()->first();

        $menus = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $revision->id)
            ->where('is_visible', true)
            ->with([
                'categories' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items.modifierGroups' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items.modifierGroups.options' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = $menus
            ->flatMap(fn (SmartOrderingMenu $menu) => $menu->categories)
            ->flatMap(fn ($category) => $category->items)
            ->filter(fn ($item) => $item instanceof SmartOrderingMenuItem)
            ->values();

        $currency = $this->quotes->currencyForStore((int) $site->store_id);
        $resolved = $this->prices->displayPrices($site, $items);
        $priceMap = $resolved['prices'];
        $priceErrors = $resolved['errors'];

        $categories = [];
        foreach ($menus as $menu) {
            foreach ($menu->categories as $category) {
                if (! $category->is_visible) {
                    continue;
                }

                $publicItems = [];
                foreach ($category->items as $item) {
                    if (! $item->is_visible || isset($priceErrors[(int) $item->id])) {
                        continue;
                    }

                    $price = $priceMap[(int) $item->id] ?? null;
                    if (! is_array($price)) {
                        continue;
                    }

                    $tags = [];
                    if ($item->vegetarian) {
                        $tags[] = ['en' => 'Vegetarian', 'ar' => 'نباتي'];
                    }
                    foreach (array_slice((array) ($item->allergens ?? []), 0, 5) as $allergen) {
                        $label = trim(strip_tags((string) $allergen));
                        if ($label !== '') {
                            $tags[] = [
                                'en' => 'Allergen: '.$label,
                                'ar' => 'مسبب حساسية: '.$label,
                            ];
                        }
                    }

                    $groups = [];
                    foreach ($item->modifierGroups as $group) {
                        if (! $group->is_visible) {
                            continue;
                        }

                        $options = [];
                        foreach ($group->options as $option) {
                            if (! $option->is_visible) {
                                continue;
                            }
                            $delta = round((float) $option->price_delta, 3);
                            $options[] = [
                                'public_key' => (string) $option->public_id,
                                'name' => ['en' => (string) $option->name_en, 'ar' => (string) ($option->name_ar ?: $option->name_en)],
                                'price_delta_display' => ($delta > 0 ? '+' : '').DisplayNumber::money($delta, $currency).' '.$currency,
                                'available' => ! (bool) $option->sold_out,
                            ];
                        }

                        $groups[] = [
                            'public_key' => (string) $group->public_id,
                            'name' => ['en' => (string) $group->name_en, 'ar' => (string) ($group->name_ar ?: $group->name_en)],
                            'required' => (bool) $group->is_required,
                            'min' => (int) $group->min_selections,
                            'max' => max(1, (int) $group->max_selections),
                            'options' => $options,
                        ];
                    }

                    $publicItems[] = [
                        'public_key' => (string) $item->public_id,
                        'name' => ['en' => (string) $item->name_en, 'ar' => (string) ($item->name_ar ?: $item->name_en)],
                        'description' => ['en' => (string) ($item->description_en ?? ''), 'ar' => (string) ($item->description_ar ?: $item->description_en ?: '')],
                        'image_url' => $this->mediaUrl($item->image_path),
                        'price_display' => DisplayNumber::money((float) $price['amount'], $currency).' '.$currency,
                        'sold_out' => (bool) $item->sold_out,
                        'featured' => (bool) $item->featured,
                        'best_seller' => (bool) $item->best_seller,
                        'new' => (bool) $item->is_new,
                        'tags' => $tags,
                        'modifiers' => $groups,
                    ];
                }

                $categories[] = [
                    'public_key' => (string) $category->public_id,
                    'name' => ['en' => (string) $category->name_en, 'ar' => (string) ($category->name_ar ?: $category->name_en)],
                    'description' => ['en' => (string) ($category->description_en ?? ''), 'ar' => (string) ($category->description_ar ?: $category->description_en ?: '')],
                    'image_url' => $this->mediaUrl($category->image_path),
                    'items' => $publicItems,
                ];
            }
        }

        $nameEn = $this->text($identity['name_en'] ?? $legacySite['name_en'] ?? $store?->name ?? 'Restaurant', 160);
        $nameAr = $this->text($identity['name_ar'] ?? $legacySite['name_ar'] ?? $nameEn, 160);
        $descriptionEn = $this->text($identity['description_en'] ?? '', 1000);
        $descriptionAr = $this->text($identity['description_ar'] ?? $descriptionEn, 1000);
        $logoUrl = $this->mediaUrl($identity['logo_url'] ?? $store?->logo_path ?? null);
        $coverUrl = $this->mediaUrl($identity['cover_url'] ?? null) ?? $this->firstMedia($categories);

        return [
            'revision' => (string) $revision->public_id,
            'locale' => 'en',
            'is_open' => (bool) ($content['is_open'] ?? true),
            'closed_message' => [
                'en' => $this->text($content['closed_message_en'] ?? 'We are currently closed', 240),
                'ar' => $this->text($content['closed_message_ar'] ?? 'المطعم مغلق الآن', 240),
            ],
            'site' => [
                'public_key' => (string) $site->public_id,
                'name' => ['en' => $nameEn, 'ar' => $nameAr],
                'tagline' => [
                    'en' => $this->text($identity['tagline_en'] ?? '', 240),
                    'ar' => $this->text($identity['tagline_ar'] ?? $identity['tagline_en'] ?? '', 240),
                ],
                'description' => ['en' => $descriptionEn, 'ar' => $descriptionAr],
                'about' => [
                    'en' => $this->text($sections['about_en'] ?? '', 1600),
                    'ar' => $this->text($sections['about_ar'] ?? $sections['about_en'] ?? '', 1600),
                ],
                'chef_note' => [
                    'en' => $this->text($sections['chef_note_en'] ?? '', 1600),
                    'ar' => $this->text($sections['chef_note_ar'] ?? $sections['chef_note_en'] ?? '', 1600),
                ],
                'allergen_note' => [
                    'en' => $this->text($sections['allergen_note_en'] ?? '', 800),
                    'ar' => $this->text($sections['allergen_note_ar'] ?? $sections['allergen_note_en'] ?? '', 800),
                ],
                'reservation_cta' => [
                    'en' => $this->text($sections['reservation_cta_en'] ?? '', 160),
                    'ar' => $this->text($sections['reservation_cta_ar'] ?? $sections['reservation_cta_en'] ?? '', 160),
                ],
                'reservation_url' => $this->text($sections['reservation_url'] ?? '', 500),
                'logo_url' => $logoUrl,
                'cover_url' => $coverUrl,
                'phone' => $this->text($identity['phone'] ?? '', 40),
                'whatsapp_url' => $this->text($identity['whatsapp_url'] ?? '', 500),
                'branches' => $this->branches($site, $content),
                'offers' => [],
                'seo' => [
                    'title' => ['en' => $this->text($identity['seo_title_en'] ?? $nameEn, 160), 'ar' => $this->text($identity['seo_title_ar'] ?? $nameAr, 160)],
                    'description' => ['en' => $this->text($identity['seo_description_en'] ?? $descriptionEn, 320), 'ar' => $this->text($identity['seo_description_ar'] ?? $descriptionAr, 320)],
                    'canonical' => null,
                    'image_url' => $coverUrl,
                ],
            ],
            'appearance' => is_array($payload['appearance'] ?? null) ? $payload['appearance'] : [],
            'menu' => ['categories' => $categories],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function branches(SmartOrderingSite $site, array $content): array
    {
        $branchContent = is_array($content['branches'] ?? null) ? $content['branches'] : [];
        $branches = $site->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('name_en')->get();
        $hours = DB::table('smart_ordering_branch_hours')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->orderBy('day_of_week')
            ->get()
            ->groupBy('branch_id');

        return $branches->map(function ($branch) use ($branchContent, $hours): array {
            $override = is_array($branchContent[(string) $branch->public_id] ?? null)
                ? $branchContent[(string) $branch->public_id]
                : [];

            return [
                'public_key' => (string) $branch->public_id,
                'name' => ['en' => (string) $branch->name_en, 'ar' => (string) ($branch->name_ar ?: $branch->name_en)],
                'address' => [
                    'en' => $this->text($override['address_en'] ?? $content['sections']['location_en'] ?? '', 500),
                    'ar' => $this->text($override['address_ar'] ?? $content['sections']['location_ar'] ?? $override['address_en'] ?? $content['sections']['location_en'] ?? '', 500),
                ],
                'hours' => $this->hours($hours->get((int) $branch->id, collect())->all()),
                'map_url' => $this->text($override['map_url'] ?? $content['sections']['map_url'] ?? '', 500),
            ];
        })->values()->all();
    }

    private function hours(array $rows): string
    {
        $open = collect($rows)->filter(fn ($row) => ! (bool) $row->is_closed && $row->opens_at !== null && $row->closes_at !== null);
        if ($open->isEmpty()) {
            return '';
        }

        return $open->map(fn ($row): string => ((int) $row->day_of_week).':'.substr((string) $row->opens_at, 0, 5).'–'.substr((string) $row->closes_at, 0, 5))
            ->implode(' · ');
    }

    private function firstMedia(array $categories): ?string
    {
        foreach ($categories as $category) {
            if (is_string($category['image_url'] ?? null) && $category['image_url'] !== '') {
                return $category['image_url'];
            }
            foreach ((array) ($category['items'] ?? []) as $item) {
                if (is_string($item['image_url'] ?? null) && $item['image_url'] !== '') {
                    return $item['image_url'];
                }
            }
        }

        return null;
    }

    private function mediaUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '/') || str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }
}
