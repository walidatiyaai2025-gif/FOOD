<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotFeatureRegistry
{
    /** @return Collection<int, \stdClass> */
    public function all(): Collection
    {
        if (! Schema::hasTable('whats_app_chatbot_features')) {
            return collect();
        }

        return DB::table('whats_app_chatbot_features')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    public function visibleEnabled(?array $segmentCodes = null): Collection
    {
        $features = $this->all()
            ->filter(static fn (object $feature): bool => (bool) $feature->enabled && (bool) $feature->visible);

        if ($segmentCodes === null || $segmentCodes === []) {
            return $features->values();
        }

        return $features->filter(function (object $feature) use ($segmentCodes): bool {
            $allowed = $this->decodeList($feature->segment_codes ?? null);

            return $allowed === [] || array_intersect($allowed, $segmentCodes) !== [];
        })->values();
    }

    public function enabled(string $code): bool
    {
        if (! Schema::hasTable('whats_app_chatbot_features')) {
            return false;
        }

        return (bool) DB::table('whats_app_chatbot_features')
            ->where('code', $code)
            ->where('enabled', true)
            ->value('enabled');
    }

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
