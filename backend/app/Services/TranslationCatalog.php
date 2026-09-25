<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class TranslationCatalog
{
    /**
     * @return array<string, array{ar: string, en: string, surface: string}>
     */
    public function defaults(): array
    {
        $catalog = [];

        foreach ([
            'admin' => 'admin',
            'notifications' => 'admin',
            'reports' => 'admin',
            'mobile_settings' => 'admin',
        ] as $group => $surface) {
            $arabic = Arr::dot(require lang_path("ar/{$group}.php"));
            $english = Arr::dot(require lang_path("en/{$group}.php"));

            foreach (array_unique([...array_keys($arabic), ...array_keys($english)]) as $key) {
                $catalog[$group.'.'.$key] = [
                    'ar' => (string) ($arabic[$key] ?? ''),
                    'en' => (string) ($english[$key] ?? ''),
                    'surface' => $surface,
                ];
            }
        }

        foreach ((array) config('ui_translations', []) as $key => $values) {
            if (! is_string($key) || ! is_array($values)) {
                continue;
            }

            $catalog[$key] = [
                'ar' => (string) ($values['ar'] ?? ''),
                'en' => (string) ($values['en'] ?? ''),
                'surface' => str_contains($key, '.') ? strstr($key, '.', true) : 'shared',
            ];
        }

        ksort($catalog);

        return $catalog;
    }

    public function syncDefaults(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();
        $rows = collect($this->defaults())
            ->map(fn (array $values, string $key): array => [
                'key' => $key,
                'surface' => $values['surface'],
                'ar' => $values['ar'],
                'en' => $values['en'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('translations')->insertOrIgnore($rows);
        }
    }

    /**
     * @return array<string, string>
     */
    public function bundle(string $locale): array
    {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported locale.');
        }

        $bundle = collect($this->defaults())
            ->mapWithKeys(fn (array $values, string $key): array => [$key => $values[$locale]])
            ->all();

        if (! Schema::hasTable('translations')) {
            return $bundle;
        }

        $overrides = DB::table('translations')
            ->whereNotNull($locale)
            ->pluck($locale, 'key')
            ->map(fn ($value): string => (string) $value)
            ->all();

        return array_replace($bundle, $overrides);
    }

    /**
     * @return array{ar: string, en: string, surface: string}|null
     */
    public function defaultsFor(string $key): ?array
    {
        return $this->defaults()[$key] ?? null;
    }
}
