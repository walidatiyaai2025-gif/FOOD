<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\WhatsApp\MarketingConsentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RestaurantCustomerService
{
    public function __construct(private readonly MarketingConsentService $consent) {}

    /** @return array<string,mixed> */
    public function profile(SmartOrderingSite $site, int $customerId): array
    {
        $customer = $this->tenantCustomer($site, $customerId);

        $orders = DB::table('orders')
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c']);

        $completed = (clone $orders)->whereRaw('LOWER(status) = ?', ['completed']);
        $summary = (clone $completed)->selectRaw(
            'MIN(created_at) as first_order_at, MAX(created_at) as last_order_at, COUNT(*) as order_count, COALESCE(SUM(grand_total),0) as lifetime_spend, COALESCE(AVG(grand_total),0) as average_order_value'
        )->first();

        $favoriteProducts = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $site->store_id)
            ->where('o.customer_id', $customerId)
            ->whereRaw('LOWER(o.status) = ?', ['completed'])
            ->groupBy('oi.product_id', 'oi.name_snapshot')
            ->orderByRaw('SUM(oi.quantity) DESC')
            ->limit(5)
            ->get([
                'oi.product_id',
                'oi.name_snapshot',
                DB::raw('SUM(oi.quantity) as quantity'),
            ])->map(static fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name_snapshot,
                'quantity' => (float) $row->quantity,
            ])->values()->all();

        $favoriteCategories = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('o.store_id', $site->store_id)
            ->where('o.customer_id', $customerId)
            ->whereRaw('LOWER(o.status) = ?', ['completed'])
            ->whereNotNull('p.category_id')
            ->groupBy('p.category_id', 'c.name')
            ->orderByRaw('SUM(oi.quantity) DESC')
            ->limit(5)
            ->get([
                'p.category_id',
                'c.name',
                DB::raw('SUM(oi.quantity) as quantity'),
            ])->map(static fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'name' => $row->name === null ? null : (string) $row->name,
                'quantity' => (float) $row->quantity,
            ])->values()->all();

        $platform = Schema::hasTable('platform_customers')
            ? DB::table('platform_customers')
                ->where('legacy_customer_id', $customerId)
                ->where('is_active', true)
                ->first(['id', 'origin_store_id', 'phone', 'email'])
            : null;

        $consentState = 'unknown';
        $marketingEligible = false;
        if ($platform !== null) {
            $consentState = $this->consent->state((int) $platform->id);
            $marketingEligible = $this->consent->isEligible((int) $platform->id, (string) ($platform->phone ?? ''));
        }

        $lastInteraction = $summary?->last_order_at;
        if ($platform !== null && Schema::hasTable('whats_app_conversations')) {
            $conversationUpdated = DB::table('whats_app_conversations')
                ->where('platform_customer_id', $platform->id)
                ->where('store_id', $site->store_id)
                ->max('updated_at');
            if ($conversationUpdated !== null && ($lastInteraction === null || $conversationUpdated > $lastInteraction)) {
                $lastInteraction = $conversationUpdated;
            }
        }

        $orderCount = (int) ($summary->order_count ?? 0);
        $lifetimeSpend = round((float) ($summary->lifetime_spend ?? 0), 3);
        $averageOrder = round((float) ($summary->average_order_value ?? 0), 3);

        return [
            'customer' => [
                'id' => $customerId,
                'name' => (string) $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
            'first_order_at' => $summary?->first_order_at,
            'last_order_at' => $summary?->last_order_at,
            'order_count' => $orderCount,
            'lifetime_spend' => $lifetimeSpend,
            'average_order_value' => $averageOrder,
            'favorite_products' => $favoriteProducts,
            'favorite_categories' => $favoriteCategories,
            'branch_affinity' => $this->branchAffinity($site, $customerId),
            'last_interaction_at' => $lastInteraction,
            'tags' => $this->tags($summary?->last_order_at, $orderCount, $lifetimeSpend, $averageOrder),
            'loyalty_balance' => (int) (DB::table('smart_ordering_loyalty_accounts')
                ->where('site_id', $site->id)
                ->where('customer_id', $customerId)
                ->value('balance') ?? 0),
            'marketing' => [
                'consent_state' => $consentState,
                'eligible' => $marketingEligible,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function orderHistory(SmartOrderingSite $site, int $customerId, ?int $limit = null): array
    {
        $this->tenantCustomer($site, $customerId);
        $limit ??= (int) config('smart_ordering.repeat_order.history_limit', 20);

        return DB::table('orders')
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 100)))
            ->get(['id', 'order_number', 'status', 'currency', 'grand_total', 'created_at'])
            ->map(static fn ($row): array => [
                'order_id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'currency' => (string) $row->currency,
                'grand_total' => (float) $row->grand_total,
                'created_at' => $row->created_at,
            ])->values()->all();
    }

    public function marketingEligible(SmartOrderingSite $site, int $customerId): bool
    {
        $this->tenantCustomer($site, $customerId);
        if (! Schema::hasTable('platform_customers')) {
            return false;
        }

        $platform = DB::table('platform_customers')
            ->where('legacy_customer_id', $customerId)
            ->where('is_active', true)
            ->first(['id', 'phone']);

        return $platform !== null
            && $this->consent->isEligible((int) $platform->id, (string) ($platform->phone ?? ''));
    }

    private function tenantCustomer(SmartOrderingSite $site, int $customerId): object
    {
        $visible = DB::table('b2c_customers')
            ->where('store_id', $site->store_id)
            ->where('legacy_customer_id', $customerId)
            ->exists()
            || DB::table('orders')
                ->where('store_id', $site->store_id)
                ->where('customer_id', $customerId)
                ->whereRaw('LOWER(channel) = ?', ['b2c'])
                ->exists();

        abort_unless($visible, 404);

        $customer = DB::table('customers')->where('id', $customerId)->first(['id', 'name', 'phone', 'email']);
        abort_unless($customer !== null, 404);

        return $customer;
    }

    /** @return array<string,mixed>|null */
    private function branchAffinity(SmartOrderingSite $site, int $customerId): ?array
    {
        if (! Schema::hasTable('smart_ordering_order_contexts')) {
            return null;
        }

        $row = DB::table('smart_ordering_order_contexts as ctx')
            ->join('orders as o', 'o.id', '=', 'ctx.order_id')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'ctx.branch_id')
            ->where('b.site_id', $site->id)
            ->where('o.store_id', $site->store_id)
            ->where('o.customer_id', $customerId)
            ->groupBy('b.id', 'b.public_id', 'b.name_ar', 'b.name_en')
            ->orderByRaw('COUNT(*) DESC')
            ->first([
                'b.public_id',
                'b.name_ar',
                'b.name_en',
                DB::raw('COUNT(*) as order_count'),
            ]);

        return $row === null ? null : [
            'id' => (string) $row->public_id,
            'name_ar' => $row->name_ar,
            'name_en' => (string) $row->name_en,
            'order_count' => (int) $row->order_count,
        ];
    }

    /** @return list<string> */
    private function tags(mixed $lastOrderAt, int $orderCount, float $lifetimeSpend, float $averageOrder): array
    {
        $tags = [];
        if ($orderCount >= (int) config('smart_ordering.customer_tags.frequent_order_count', 5)) {
            $tags[] = 'FREQUENT';
        }
        if ($lifetimeSpend >= (float) config('smart_ordering.customer_tags.vip_lifetime_spend', 250)) {
            $tags[] = 'VIP';
        }
        if ($averageOrder >= (float) config('smart_ordering.customer_tags.high_spender_average_order', 35)) {
            $tags[] = 'HIGH_SPENDER';
        }
        if ($lastOrderAt !== null) {
            $cutoff = now()->subDays((int) config('smart_ordering.customer_tags.dormant_days', 60));
            if (Carbon::parse($lastOrderAt)->lt($cutoff)) {
                $tags[] = 'DORMANT';
            }
        }

        return $tags;
    }
}
