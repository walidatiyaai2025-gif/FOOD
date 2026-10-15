<?php

namespace App\Domain\WhatsApp;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class CampaignSegmentationService
{
    /** @param array<string, mixed> $segment
     * @return Collection<int, stdClass>
     */
    public function customers(array $segment): Collection
    {
        $query = DB::table('platform_customers as pc')
            ->where('pc.is_active', true)
            ->whereNotNull('pc.phone')
            ->select([
                'pc.id',
                'pc.name',
                'pc.phone',
                'pc.email',
                'pc.origin_channel',
                'pc.origin_store_id',
                'pc.legacy_customer_id',
            ]);

        $this->applyIds($query, 'pc.id', $segment['customer_ids'] ?? null);
        $this->applyIds($query, 'pc.origin_store_id', $segment['store_ids'] ?? null);

        $search = trim((string) ($segment['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('pc.name', 'like', $like)
                    ->orWhere('pc.phone', 'like', $like)
                    ->orWhere('pc.email', 'like', $like);
            });
        }

        $channels = $this->stringList($segment['channels'] ?? null, ['b2b', 'b2c']);
        if ($channels !== []) {
            $query->whereIn('pc.origin_channel', $channels);
        }

        if (($segment['agent_ids'] ?? null) !== null) {
            $agentIds = $this->intList($segment['agent_ids']);
            if ($agentIds !== []) {
                $query->whereExists(function (Builder $sub) use ($agentIds): void {
                    $sub->selectRaw('1')
                        ->from('whats_app_customer_assignments as wca')
                        ->whereColumn('wca.platform_customer_id', 'pc.id')
                        ->where('wca.is_active', true)
                        ->whereIn('wca.primary_agent_id', $agentIds);
                });
            }
        }

        $tagIds = $this->intList($segment['tag_ids'] ?? null);
        if ($tagIds !== []) {
            $query->whereExists(function (Builder $sub) use ($tagIds): void {
                $sub->selectRaw('1')
                    ->from('whats_app_conversations as wc')
                    ->join('whats_app_conversation_tag as wct', 'wct.conversation_id', '=', 'wc.id')
                    ->whereColumn('wc.platform_customer_id', 'pc.id')
                    ->whereIn('wct.tag_id', $tagIds);
            });
        }

        $minValue = $this->numeric($segment['min_value'] ?? null);
        if ($minValue !== null) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE o.customer_id = pc.legacy_customer_id AND o.status NOT IN (?, ?)) >= ?',
                ['cancelled', 'refunded', $minValue],
            );
        }

        $maxValue = $this->numeric($segment['max_value'] ?? null);
        if ($maxValue !== null) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE o.customer_id = pc.legacy_customer_id AND o.status NOT IN (?, ?)) <= ?',
                ['cancelled', 'refunded', $maxValue],
            );
        }

        $recencyDays = filter_var($segment['recency_days'] ?? null, FILTER_VALIDATE_INT);
        if ($recencyDays !== false && $recencyDays >= 0) {
            $query->whereExists(function (Builder $sub) use ($recencyDays): void {
                $sub->selectRaw('1')
                    ->from('orders as o')
                    ->whereColumn('o.customer_id', 'pc.legacy_customer_id')
                    ->where('o.created_at', '>=', now()->subDays((int) $recencyDays))
                    ->whereNotIn('o.status', ['cancelled', 'refunded']);
            });
        }

        $categoryIds = $this->intList($segment['category_ids'] ?? null);
        if ($categoryIds !== []) {
            $query->whereExists(function (Builder $sub) use ($categoryIds): void {
                $sub->selectRaw('1')
                    ->from('orders as o')
                    ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->whereColumn('o.customer_id', 'pc.legacy_customer_id')
                    ->whereIn('p.category_id', $categoryIds)
                    ->whereNotIn('o.status', ['cancelled', 'refunded']);
            });
        }

        return $query->orderBy('pc.id')->get();
    }

    private function applyIds(Builder $query, string $column, mixed $value): void
    {
        $ids = $this->intList($value);
        if ($ids !== []) {
            $query->whereIn($column, $ids);
        }
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $value),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /** @param list<string> $allowed
     * @return list<string>
     */
    private function stringList(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $value),
            static fn (string $item): bool => in_array($item, $allowed, true),
        )));
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
