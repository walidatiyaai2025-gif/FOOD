<?php

namespace App\Domain\RestaurantSaaS;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RestaurantGrowthScaleService
{
    public function __construct(private readonly OrganizationHierarchyService $hierarchy) {}

    /** @return array{public_id:string,code:string,balance:float} */
    public function issueGiftCard(
        Store $store,
        User $actor,
        float $amount,
        string $currency,
        string $idempotencyKey,
        string $scope = 'brand',
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        abort_unless($amount > 0, 422);
        $currency = Str::upper(trim($currency));
        abort_unless((bool) preg_match('/^[A-Z]{3}$/', $currency), 422);
        abort_unless(in_array($scope, ['organization', 'brand', 'location'], true), 422);

        $enterprise = $this->scope($store);
        $companyId = $scope === 'organization' ? null : (int) $enterprise['company_id'];
        $storeId = $scope === 'location' ? (int) $store->getKey() : null;
        $issuanceKey = mb_substr(trim($idempotencyKey), 0, 128);
        abort_if($issuanceKey === '', 422, 'Gift-card issuance requires an idempotency key.');

        return DB::transaction(function () use (
            $store,
            $actor,
            $amount,
            $currency,
            $issuanceKey,
            $expiresAt,
            $enterprise,
            $companyId,
            $storeId,
        ): array {
            $existing = DB::table('restaurant_saas_gift_cards')
                ->where('organization_id', (int) $enterprise['organization_id'])
                ->where('issuance_key', $issuanceKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $issuedAmount = round((float) DB::table('restaurant_saas_gift_card_entries')
                    ->where('gift_card_id', $existing->id)
                    ->where('entry_type', 'issue')
                    ->sum('amount'), 3);
                abort_if(
                    $issuedAmount !== round($amount, 3)
                    || (string) $existing->currency !== $currency
                    || ($existing->company_id === null ? null : (int) $existing->company_id) !== $companyId
                    || ($existing->store_id === null ? null : (int) $existing->store_id) !== $storeId,
                    409,
                    'Gift-card issuance idempotency key was reused with different parameters.',
                );

                $balance = round((float) DB::table('restaurant_saas_gift_card_entries')
                    ->where('gift_card_id', $existing->id)
                    ->sum('amount'), 3);

                return [
                    'public_id' => (string) $existing->public_id,
                    'code' => Crypt::decryptString((string) $existing->code_ciphertext),
                    'balance' => $balance,
                ];
            }

            $code = 'GC-'.Str::upper(Str::random(20));
            $publicId = (string) Str::uuid();
            $cardId = (int) DB::table('restaurant_saas_gift_cards')->insertGetId([
                'public_id' => $publicId,
                'organization_id' => (int) $enterprise['organization_id'],
                'company_id' => $companyId,
                'store_id' => $storeId,
                'code_hash' => hash('sha256', $code),
                'code_ciphertext' => Crypt::encryptString($code),
                'issuance_key' => $issuanceKey,
                'display_code' => '•••• '.substr($code, -4),
                'currency' => $currency,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'issued_by' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('restaurant_saas_gift_card_entries')->insert([
                'gift_card_id' => $cardId,
                'idempotency_key' => 'issue:'.$issuanceKey,
                'entry_type' => 'issue',
                'amount' => round($amount, 3),
                'reference' => 'store:'.$store->getKey(),
                'actor_id' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['public_id' => $publicId, 'code' => $code, 'balance' => round($amount, 3)];
        }, 3);
    }

    /** @return array{public_id:string,balance:float,duplicate:bool} */
    public function redeemGiftCard(
        Store $store,
        User $actor,
        string $code,
        float $amount,
        string $idempotencyKey,
        ?string $reference = null,
    ): array {
        abort_unless($amount > 0, 422);
        $enterprise = $this->scope($store);
        $codeHash = hash('sha256', trim($code));

        return DB::transaction(function () use (
            $store,
            $actor,
            $amount,
            $idempotencyKey,
            $reference,
            $enterprise,
            $codeHash,
        ): array {
            $card = DB::table('restaurant_saas_gift_cards')
                ->where('organization_id', (int) $enterprise['organization_id'])
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();
            abort_unless($card !== null, 404);
            abort_if($card->company_id !== null && (int) $card->company_id !== (int) $enterprise['company_id'], 404);
            abort_if($card->store_id !== null && (int) $card->store_id !== (int) $store->getKey(), 404);

            $key = mb_substr(trim($idempotencyKey), 0, 128);
            abort_if($key === '', 422, 'Gift-card redemption requires an idempotency key.');
            $existing = DB::table('restaurant_saas_gift_card_entries')
                ->where('gift_card_id', $card->id)
                ->where('idempotency_key', $key)
                ->first(['id', 'amount', 'reference']);
            $balance = round((float) DB::table('restaurant_saas_gift_card_entries')
                ->where('gift_card_id', $card->id)
                ->sum('amount'), 3);

            if ($existing !== null) {
                abort_if(
                    round(abs((float) $existing->amount), 3) !== round($amount, 3)
                    || ($existing->reference === null ? null : (string) $existing->reference) !== ($reference === null ? null : mb_substr($reference, 0, 128)),
                    409,
                    'Gift-card redemption idempotency key was reused with different parameters.',
                );

                return ['public_id' => (string) $card->public_id, 'balance' => $balance, 'duplicate' => true];
            }

            abort_unless($card->status === 'active', 409, 'Gift card is not active.');
            abort_if($card->expires_at !== null && now()->gte($card->expires_at), 409, 'Gift card has expired.');
            abort_if($balance < $amount, 409, 'Insufficient gift-card balance.');
            DB::table('restaurant_saas_gift_card_entries')->insert([
                'gift_card_id' => (int) $card->id,
                'idempotency_key' => $key,
                'entry_type' => 'redeem',
                'amount' => -round($amount, 3),
                'reference' => $reference === null ? null : mb_substr($reference, 0, 128),
                'actor_id' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'public_id' => (string) $card->public_id,
                'balance' => round($balance - $amount, 3),
                'duplicate' => false,
            ];
        }, 3);
    }

    public function setGiftCardStatus(
        Store $store,
        string $publicId,
        string $status,
    ): void {
        abort_unless(in_array($status, ['active', 'suspended', 'closed'], true), 422);
        $enterprise = $this->scope($store);

        DB::transaction(function () use ($enterprise, $publicId, $status): void {
            $card = DB::table('restaurant_saas_gift_cards')
                ->where('organization_id', (int) $enterprise['organization_id'])
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first(['id', 'status']);
            abort_unless($card !== null, 404);
            abort_if($card->status === 'closed' && $status !== 'closed', 409, 'Closed gift cards cannot be reactivated.');

            DB::table('restaurant_saas_gift_cards')
                ->where('id', $card->id)
                ->update(['status' => $status, 'updated_at' => now()]);
        }, 3);
    }

    /** @param array<string,mixed> $configuration */
    public function createJourney(
        Store $store,
        User $actor,
        string $name,
        string $triggerEvent,
        string $channel,
        bool $consentRequired,
        ?string $segmentKey = null,
        array $configuration = [],
        string $scope = 'brand',
    ): string {
        abort_unless(in_array($channel, ['whatsapp', 'push', 'email', 'internal'], true), 422);
        abort_unless(in_array($scope, ['organization', 'brand', 'location'], true), 422);
        $enterprise = $this->scope($store);
        $publicId = (string) Str::uuid();

        DB::table('restaurant_saas_lifecycle_journeys')->insert([
            'public_id' => $publicId,
            'organization_id' => (int) $enterprise['organization_id'],
            'company_id' => $scope === 'organization' ? null : (int) $enterprise['company_id'],
            'store_id' => $scope === 'location' ? (int) $store->getKey() : null,
            'name' => mb_substr(trim($name), 0, 120),
            'trigger_event' => mb_substr(trim($triggerEvent), 0, 120),
            'segment_key' => $segmentKey === null ? null : mb_substr(trim($segmentKey), 0, 120),
            'channel' => $channel,
            'consent_required' => $consentRequired,
            'is_active' => true,
            'configuration' => $configuration === [] ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
            'created_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /** @return array{status:string,duplicate:bool} */
    public function triggerJourney(
        Store $store,
        string $journeyPublicId,
        string $eventKey,
        string $subjectReference,
        bool $consentGranted,
        ?\DateTimeInterface $occurredAt = null,
    ): array {
        $enterprise = $this->scope($store);
        $journey = DB::table('restaurant_saas_lifecycle_journeys')
            ->where('public_id', $journeyPublicId)
            ->where('organization_id', (int) $enterprise['organization_id'])
            ->where('is_active', true)
            ->where(function ($q) use ($enterprise): void {
                $q->whereNull('company_id')->orWhere('company_id', (int) $enterprise['company_id']);
            })
            ->where(function ($q) use ($store): void {
                $q->whereNull('store_id')->orWhere('store_id', (int) $store->getKey());
            })
            ->first();
        abort_unless($journey !== null, 404);

        $normalizedEventKey = mb_substr(trim($eventKey), 0, 160);
        abort_if($normalizedEventKey === '', 422, 'Lifecycle event requires an idempotency key.');

        $existing = DB::table('restaurant_saas_lifecycle_events')
            ->where('journey_id', $journey->id)
            ->where('event_key', $normalizedEventKey)
            ->first(['status']);
        if ($existing !== null) {
            return ['status' => (string) $existing->status, 'duplicate' => true];
        }

        $suppressed = (bool) $journey->consent_required && ! $consentGranted;
        $status = $suppressed ? 'suppressed' : 'pending';
        DB::table('restaurant_saas_lifecycle_events')->insert([
            'journey_id' => (int) $journey->id,
            'event_key' => $normalizedEventKey,
            'subject_reference' => mb_substr(trim($subjectReference), 0, 190),
            'status' => $status,
            'reason_code' => $suppressed ? 'consent_required' : null,
            'occurred_at' => $occurredAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => $status, 'duplicate' => false];
    }

    /** @param array<string,mixed>|list<mixed>|string|int|float|bool|null $value */
    public function setFranchisePolicy(
        Store $store,
        User $actor,
        string $level,
        string $policyKey,
        mixed $value,
        bool $locked,
    ): void {
        abort_unless(in_array($level, ['organization', 'brand', 'location'], true), 422);
        $enterprise = $this->scope($store);
        $organizationId = (int) $enterprise['organization_id'];
        $companyId = $level === 'organization' ? null : (int) $enterprise['company_id'];
        $storeId = $level === 'location' ? (int) $store->getKey() : null;

        if ($level !== 'organization') {
            $parent = DB::table('restaurant_saas_franchise_policies')
                ->where('organization_id', $organizationId)
                ->where('policy_key', $policyKey)
                ->where('is_locked', true)
                ->where(function ($q) use ($level, $enterprise): void {
                    $q->whereNull('company_id');
                    if ($level === 'location') {
                        $q->orWhere(function ($child) use ($enterprise): void {
                            $child->where('company_id', (int) $enterprise['company_id'])->whereNull('store_id');
                        });
                    }
                })
                ->exists();
            abort_if($parent, 409, 'This franchise policy is locked by a parent scope.');
        }

        $scopeKey = match ($level) {
            'organization' => 'organization:'.$organizationId,
            'brand' => 'company:'.$companyId,
            default => 'store:'.$storeId,
        };

        $normalizedKey = mb_substr(trim($policyKey), 0, 120);
        $existing = DB::table('restaurant_saas_franchise_policies')
            ->where('scope_key', $scopeKey)
            ->where('policy_key', $normalizedKey)
            ->first(['id']);

        $values = [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'store_id' => $storeId,
            'policy_value' => json_encode($value, JSON_THROW_ON_ERROR),
            'is_locked' => $locked,
            'effective_from' => now(),
            'updated_by' => (int) $actor->getKey(),
            'updated_at' => now(),
        ];
        if ($existing === null) {
            DB::table('restaurant_saas_franchise_policies')->insert([
                'public_id' => (string) Str::uuid(),
                'scope_key' => $scopeKey,
                'policy_key' => $normalizedKey,
                ...$values,
                'created_at' => now(),
            ]);
        } else {
            DB::table('restaurant_saas_franchise_policies')->where('id', $existing->id)->update($values);
        }
    }

    /** @return array{source:string,value:mixed,locked:bool}|null */
    public function effectivePolicy(Store $store, string $policyKey): ?array
    {
        $enterprise = $this->scope($store);
        $organizationKey = 'organization:'.$enterprise['organization_id'];
        $companyKey = 'company:'.$enterprise['company_id'];
        $storeKey = 'store:'.$store->getKey();

        $rows = DB::table('restaurant_saas_franchise_policies')
            ->whereIn('scope_key', [$organizationKey, $companyKey, $storeKey])
            ->where('policy_key', $policyKey)
            ->get(['scope_key', 'policy_value', 'is_locked'])
            ->keyBy('scope_key');

        foreach ([$organizationKey, $companyKey] as $parentKey) {
            $parent = $rows->get($parentKey);
            if ($parent !== null && (bool) $parent->is_locked) {
                return [
                    'source' => (string) $parent->scope_key,
                    'value' => json_decode((string) $parent->policy_value, true),
                    'locked' => true,
                ];
            }
        }

        foreach ([$storeKey, $companyKey, $organizationKey] as $scopeKey) {
            $row = $rows->get($scopeKey);
            if ($row !== null) {
                return [
                    'source' => (string) $row->scope_key,
                    'value' => json_decode((string) $row->policy_value, true),
                    'locked' => (bool) $row->is_locked,
                ];
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function analytics(Store $store, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $this->assertReportingWindow($from, $to);
        $enterprise = $this->scope($store);
        $storeIds = $this->organizationStoreIds((int) $enterprise['organization_id']);

        $sales = DB::table('smart_ordering_order_contexts as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->whereIn('o.store_id', $storeIds)
            ->whereBetween('o.created_at', [$from, $to])
            ->groupBy('o.store_id')
            ->selectRaw(
                "o.store_id,
                 COUNT(*) as order_count,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_count,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.grand_total ELSE 0 END) as completed_sales,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.discount_total ELSE 0 END) as discounts,
                 SUM(CASE WHEN c.lifecycle_status IN ('CANCELLED','REFUNDED') THEN 1 ELSE 0 END) as exception_count"
            )
            ->get()
            ->keyBy('store_id');

        $reservations = Schema::hasTable('smart_ordering_reservations')
            ? DB::table('smart_ordering_reservations as r')
                ->join('smart_ordering_branches as b', 'b.id', '=', 'r.branch_id')
                ->join('smart_ordering_sites as s', 's.id', '=', 'b.site_id')
                ->whereIn('s.store_id', $storeIds)
                ->whereBetween('r.service_at', [$from, $to])
                ->groupBy('s.store_id')
                ->selectRaw("s.store_id, COUNT(*) as reservations, SUM(CASE WHEN r.status = 'NO_SHOW' THEN 1 ELSE 0 END) as no_shows")
                ->get()
                ->keyBy('store_id')
            : collect();

        $stores = DB::table('stores')->whereIn('id', $storeIds)->orderBy('name')->get(['id', 'code', 'name']);

        return [
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'formulas' => [
                'completed_sales' => 'SUM(canonical order grand_total where Smart Ordering lifecycle_status=COMPLETED)',
                'average_ticket' => 'completed_sales / completed_count',
                'reservation_no_show_rate' => 'no_shows / reservations',
            ],
            'locations' => $stores->map(function (object $location) use ($sales, $reservations): array {
                $sale = $sales->get($location->id);
                $reservation = $reservations->get($location->id);
                $completed = (int) ($sale->completed_count ?? 0);
                $gross = round((float) ($sale->completed_sales ?? 0), 3);
                $reservationCount = (int) ($reservation->reservations ?? 0);
                $noShows = (int) ($reservation->no_shows ?? 0);

                return [
                    'store_id' => (int) $location->id,
                    'code' => (string) $location->code,
                    'name' => (string) $location->name,
                    'orders' => (int) ($sale->order_count ?? 0),
                    'completed_orders' => $completed,
                    'completed_sales' => $gross,
                    'discounts' => round((float) ($sale->discounts ?? 0), 3),
                    'exceptions' => (int) ($sale->exception_count ?? 0),
                    'average_ticket' => $completed > 0 ? round($gross / $completed, 3) : 0.0,
                    'reservations' => $reservationCount,
                    'no_shows' => $noShows,
                    'reservation_no_show_rate' => $reservationCount > 0 ? round($noShows / $reservationCount, 4) : 0.0,
                ];
            })->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function accountingSummary(Store $store, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $this->assertReportingWindow($from, $to);
        $enterprise = $this->scope($store);
        $storeIds = $this->organizationStoreIds((int) $enterprise['organization_id']);

        $rows = DB::table('smart_ordering_order_contexts as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->whereIn('o.store_id', $storeIds)
            ->whereBetween('o.created_at', [$from, $to])
            ->groupBy('o.store_id', 'o.currency')
            ->groupByRaw('DATE(o.created_at)')
            ->selectRaw(
                "DATE(o.created_at) as sales_date, o.store_id, o.currency,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.subtotal ELSE 0 END) as subtotal,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.discount_total ELSE 0 END) as discounts,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.delivery_total ELSE 0 END) as delivery,
                 SUM(CASE WHEN c.lifecycle_status = 'COMPLETED' THEN o.grand_total ELSE 0 END) as net_sales,
                 SUM(CASE WHEN c.lifecycle_status IN ('REFUNDED') THEN o.grand_total ELSE 0 END) as refunded_value"
            )
            ->get();

        return [
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'source' => 'canonical_orders_and_smart_ordering_lifecycle',
            'rows' => $rows->map(static fn (object $row): array => [
                'sales_date' => (string) $row->sales_date,
                'store_id' => (int) $row->store_id,
                'currency' => (string) $row->currency,
                'subtotal' => round((float) $row->subtotal, 3),
                'discounts' => round((float) $row->discounts, 3),
                'delivery' => round((float) $row->delivery, 3),
                'net_sales' => round((float) $row->net_sales, 3),
                'refunded_value' => round((float) $row->refunded_value, 3),
            ])->all(),
        ];
    }

    /** @return array{public_id:string,checksum:string,file_path:string,record_counts:array<string,int>} */
    public function exportOrganization(Store $store, User $actor): array
    {
        $enterprise = $this->scope($store);
        $organizationId = (int) $enterprise['organization_id'];
        $storeIds = $this->organizationStoreIds($organizationId);
        $siteIds = DB::table('smart_ordering_sites')->whereIn('store_id', $storeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchIds = DB::table('smart_ordering_branches')->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $menuIds = DB::table('smart_ordering_menus')->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $categoryIds = DB::table('smart_ordering_menu_categories')->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemIds = DB::table('smart_ordering_menu_items')->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $groupIds = DB::table('smart_ordering_modifier_groups')->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $optionIds = DB::table('smart_ordering_modifier_options')->whereIn('group_id', $groupIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orderIds = DB::table('orders')->whereIn('store_id', $storeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $customerIds = DB::table('orders')
            ->whereIn('store_id', $storeIds)
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id);
        foreach ([
            'smart_ordering_repeat_drafts',
            'smart_ordering_loyalty_accounts',
            'smart_ordering_loyalty_ledger',
            'smart_ordering_feedback',
            'smart_ordering_service_recovery_cases',
        ] as $customerScopeTable) {
            if (Schema::hasTable($customerScopeTable)) {
                $customerIds = $customerIds->merge(
                    DB::table($customerScopeTable)
                        ->whereIn('site_id', $siteIds)
                        ->pluck('customer_id')
                        ->map(fn ($id) => (int) $id),
                );
            }
        }
        $customerIds = $customerIds->filter()->unique()->sort()->values()->all();

        $payload = [
            'schema' => 'foodex.restaurant-saas.export.v1',
            'generated_at' => now()->utc()->toIso8601String(),
            'organization_id' => $organizationId,
            'stores' => DB::table('stores')->whereIn('id', $storeIds)->orderBy('id')->get(['id', 'code', 'name', 'is_active'])->all(),
            'location_profiles' => DB::table('restaurant_location_profiles')->whereIn('store_id', $storeIds)->orderBy('id')->get()->all(),
            'sites' => DB::table('smart_ordering_sites')->whereIn('store_id', $storeIds)->orderBy('id')->get(['public_id', 'store_id', 'slug', 'runtime_state', 'master_enabled', 'custom_domain', 'custom_domain_verified_at'])->all(),
            'branches' => DB::table('smart_ordering_branches')->whereIn('site_id', $siteIds)->orderBy('id')->get(['public_id', 'site_id', 'code', 'name_ar', 'name_en', 'timezone', 'is_active'])->all(),
            'entitlements' => DB::table('smart_ordering_entitlements')->whereIn('site_id', $siteIds)->orderBy('id')->get(['site_id', 'branch_id', 'scope_key', 'module', 'enabled'])->all(),
            'menus' => DB::table('smart_ordering_menus')->whereIn('site_id', $siteIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'revision_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'is_default', 'is_visible', 'sort_order'])->all(),
            'menu_branches' => DB::table('smart_ordering_menu_branches')->whereIn('menu_id', $menuIds)->whereIn('branch_id', $branchIds)->orderBy('menu_id')->orderBy('branch_id')->limit(50000)->get(['menu_id', 'branch_id'])->all(),
            'menu_categories' => DB::table('smart_ordering_menu_categories')->whereIn('site_id', $siteIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'revision_id', 'menu_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'image_path', 'icon', 'is_visible', 'sold_out', 'availability_schedule', 'sort_order'])->all(),
            'menu_category_branch_overrides' => DB::table('smart_ordering_category_branch_overrides')->whereIn('category_id', $categoryIds)->whereIn('branch_id', $branchIds)->orderBy('id')->limit(50000)->get(['category_id', 'branch_id', 'is_visible', 'sold_out', 'availability_schedule', 'sort_order'])->all(),
            'menu_items' => DB::table('smart_ordering_menu_items')->whereIn('site_id', $siteIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'revision_id', 'category_id', 'source_product_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'image_path', 'gallery', 'price_override', 'featured', 'best_seller', 'is_new', 'is_visible', 'sold_out', 'availability_schedule', 'prep_time_minutes', 'calories', 'allergens', 'spicy_level', 'vegetarian', 'allow_customer_notes', 'removable_ingredients', 'sort_order'])->all(),
            'menu_item_branch_overrides' => DB::table('smart_ordering_item_branch_overrides')->whereIn('item_id', $itemIds)->whereIn('branch_id', $branchIds)->orderBy('id')->limit(50000)->get(['item_id', 'branch_id', 'price_override', 'is_visible', 'sold_out', 'availability_schedule', 'prep_time_minutes'])->all(),
            'modifier_groups' => DB::table('smart_ordering_modifier_groups')->whereIn('site_id', $siteIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'revision_id', 'item_id', 'name_ar', 'name_en', 'kind', 'selection_type', 'is_required', 'min_selections', 'max_selections', 'is_visible', 'sort_order'])->all(),
            'modifier_options' => DB::table('smart_ordering_modifier_options')->whereIn('group_id', $groupIds)->orderBy('id')->limit(50000)->get(['public_id', 'group_id', 'source_product_id', 'name_ar', 'name_en', 'price_delta', 'is_default', 'is_visible', 'sold_out', 'sort_order'])->all(),
            'modifier_option_branch_overrides' => DB::table('smart_ordering_modifier_option_branch_overrides')->whereIn('option_id', $optionIds)->whereIn('branch_id', $branchIds)->orderBy('id')->limit(50000)->get(['option_id', 'branch_id', 'price_delta', 'is_visible', 'sold_out'])->all(),
            'customers' => DB::table('customers')->whereIn('id', $customerIds)->orderBy('id')->limit(50000)->get(['id', 'type', 'name', 'phone', 'email', 'created_at', 'updated_at'])->all(),
            'customer_addresses' => DB::table('addresses')->whereIn('customer_id', $customerIds)->orderBy('id')->limit(50000)->get(['id', 'customer_id', 'label', 'line1', 'line2', 'city', 'area', 'country_code', 'latitude', 'longitude', 'is_default', 'created_at', 'updated_at'])->all(),
            'customer_loyalty_accounts' => Schema::hasTable('smart_ordering_loyalty_accounts') ? DB::table('smart_ordering_loyalty_accounts')->whereIn('site_id', $siteIds)->whereIn('customer_id', $customerIds)->orderBy('id')->limit(50000)->get(['site_id', 'customer_id', 'balance', 'lifetime_earned', 'lifetime_redeemed', 'created_at', 'updated_at'])->all() : [],
            'customer_loyalty_ledger' => Schema::hasTable('smart_ordering_loyalty_ledger') ? DB::table('smart_ordering_loyalty_ledger')->whereIn('site_id', $siteIds)->whereIn('customer_id', $customerIds)->orderBy('id')->limit(50000)->get(['site_id', 'customer_id', 'branch_id', 'order_id', 'reward_id', 'type', 'points', 'balance_after', 'expires_at', 'created_at'])->all() : [],
            'customer_feedback' => Schema::hasTable('smart_ordering_feedback') ? DB::table('smart_ordering_feedback')->whereIn('site_id', $siteIds)->whereIn('customer_id', $customerIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'order_id', 'customer_id', 'rating', 'feedback', 'external_review_offered', 'submitted_at'])->all() : [],
            'customer_service_recovery' => Schema::hasTable('smart_ordering_service_recovery_cases') ? DB::table('smart_ordering_service_recovery_cases')->whereIn('site_id', $siteIds)->whereIn('customer_id', $customerIds)->orderBy('id')->limit(50000)->get(['public_id', 'site_id', 'feedback_id', 'order_id', 'customer_id', 'status', 'reason', 'resolution_note', 'resolved_at'])->all() : [],
            'orders' => DB::table('orders')->whereIn('id', $orderIds)->orderBy('id')->limit(50000)->get(['id', 'store_id', 'customer_id', 'address_id', 'order_number', 'channel', 'status', 'currency', 'subtotal', 'discount_total', 'delivery_total', 'grand_total', 'created_at', 'updated_at'])->all(),
            'order_items' => DB::table('order_items')->whereIn('order_id', $orderIds)->orderBy('id')->limit(50000)->get(['order_id', 'product_id', 'sku_snapshot', 'name_snapshot', 'quantity', 'unit_price', 'line_total', 'created_at', 'updated_at'])->all(),
            'smart_ordering_order_contexts' => DB::table('smart_ordering_order_contexts')->whereIn('order_id', $orderIds)->orderBy('id')->limit(50000)->get(['public_reference', 'order_id', 'branch_id', 'fulfillment_mode', 'lifecycle_status', 'created_at', 'updated_at'])->all(),
            'reservations' => Schema::hasTable('smart_ordering_reservations') ? DB::table('smart_ordering_reservations')->whereIn('branch_id', $branchIds)->orderBy('id')->limit(50000)->get(['public_id', 'branch_id', 'customer_id', 'party_size', 'service_at', 'status', 'created_at'])->all() : [],
            'gift_cards' => DB::table('restaurant_saas_gift_cards')->where('organization_id', $organizationId)->orderBy('id')->get(['public_id', 'company_id', 'store_id', 'display_code', 'currency', 'status', 'expires_at', 'created_at'])->all(),
            'gift_card_entries' => DB::table('restaurant_saas_gift_card_entries as e')->join('restaurant_saas_gift_cards as g', 'g.id', '=', 'e.gift_card_id')->where('g.organization_id', $organizationId)->orderBy('e.id')->limit(50000)->get(['g.public_id as gift_card_public_id', 'e.entry_type', 'e.amount', 'e.reference', 'e.created_at'])->all(),
            'franchise_policies' => DB::table('restaurant_saas_franchise_policies')->where('organization_id', $organizationId)->orderBy('id')->get(['public_id', 'scope_key', 'policy_key', 'policy_value', 'is_locked', 'effective_from'])->all(),
            'lifecycle_journeys' => DB::table('restaurant_saas_lifecycle_journeys')->where('organization_id', $organizationId)->orderBy('id')->get(['public_id', 'company_id', 'store_id', 'name', 'trigger_event', 'segment_key', 'channel', 'consent_required', 'is_active'])->all(),
        ];

        $recordCounts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $recordCounts[$key] = count($value);
            }
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $json);
        $publicId = (string) Str::uuid();
        $path = 'private/restaurant-exports/'.$publicId.'.json';
        Storage::disk('local')->put($path, $json);

        DB::table('restaurant_saas_export_requests')->insert([
            'public_id' => $publicId,
            'organization_id' => $organizationId,
            'status' => 'completed',
            'scope_manifest' => json_encode(array_keys($recordCounts), JSON_THROW_ON_ERROR),
            'file_path' => $path,
            'checksum_sha256' => $checksum,
            'record_counts' => json_encode($recordCounts, JSON_THROW_ON_ERROR),
            'requested_by' => (int) $actor->getKey(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['public_id' => $publicId, 'checksum' => $checksum, 'file_path' => $path, 'record_counts' => $recordCounts];
    }

    public function revokeStoreExternalAccess(Store $store): array
    {
        $siteIds = DB::table('smart_ordering_sites')->where('store_id', $store->getKey())->pluck('id')->all();
        $result = ['api_clients' => 0, 'integrations' => 0, 'webhooks' => 0];

        if ($siteIds !== [] && Schema::hasTable('restaurant_saas_api_clients')) {
            $result['api_clients'] = DB::table('restaurant_saas_api_clients')->whereIn('site_id', $siteIds)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
        }
        if ($siteIds !== [] && Schema::hasTable('restaurant_saas_integrations')) {
            $result['integrations'] = DB::table('restaurant_saas_integrations')->whereIn('site_id', $siteIds)->whereNotIn('status', ['disabled', 'suspended'])->update(['status' => 'suspended', 'updated_at' => now()]);
        }
        if ($siteIds !== [] && Schema::hasTable('restaurant_saas_webhook_subscriptions')) {
            $result['webhooks'] = DB::table('restaurant_saas_webhook_subscriptions')->whereIn('site_id', $siteIds)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
        }

        return $result;
    }

    private function assertReportingWindow(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        abort_if($seconds < 0, 422, 'Reporting period end must not precede its start.');
        abort_if($seconds > 366 * 86400, 422, 'Reporting period is limited to 366 days.');
    }

    /** @return array{organization_id:int,company_id:int,legal_entity_id:int,business_unit_id:int,store_id:int} */
    private function scope(Store $store): array
    {
        $scope = $this->hierarchy->scopeForStore((int) $store->getKey());
        abort_unless(is_array($scope), 409, 'Restaurant Enterprise scope is not provisioned.');

        return $scope;
    }

    /** @return list<int> */
    private function organizationStoreIds(int $organizationId): array
    {
        return DB::table('enterprise_store_scopes as ss')
            ->join('enterprise_business_units as bu', 'bu.id', '=', 'ss.business_unit_id')
            ->join('enterprise_legal_entities as le', 'le.id', '=', 'bu.legal_entity_id')
            ->join('enterprise_companies as c', 'c.id', '=', 'le.company_id')
            ->where('c.organization_id', $organizationId)
            ->distinct()
            ->orderBy('ss.store_id')
            ->pluck('ss.store_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
