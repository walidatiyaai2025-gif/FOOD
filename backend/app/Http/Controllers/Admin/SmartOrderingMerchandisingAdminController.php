<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMerchandisingService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SmartOrderingMerchandisingAdminController extends Controller
{
    private const OFFER_TYPES = [
        'promotion',
        'percentage',
        'fixed',
        'fixed_discount',
        'item_discount',
        'buy_x_get_y',
        'combo',
        'bundle',
        'free_item',
        'free_delivery',
        'coupon',
    ];

    public function index(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        return response()->json([
            'offers' => DB::table('smart_ordering_offer_rules')
                ->where('site_id', $site->id)
                ->orderBy('priority')->orderBy('id')->get()
                ->map(fn ($row): array => $this->offerResource($row))->all(),
            'recommendations' => DB::table('smart_ordering_recommendation_rules')
                ->where('site_id', $site->id)
                ->orderBy('placement')->orderBy('priority')->orderBy('id')->get()
                ->map(fn ($row): array => $this->recommendationResource($row))->all(),
        ]);
    }

    public function storeOffer(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $this->offerData($request, $site);

        $id = (int) DB::table('smart_ordering_offer_rules')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->id,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(
            $this->offerResource(DB::table('smart_ordering_offer_rules')->where('id', $id)->first()),
            201,
        );
    }

    public function updateOffer(
        Request $request,
        SmartOrderingSite $site,
        string $offer,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $row = DB::table('smart_ordering_offer_rules')
            ->where('site_id', $site->id)->where('public_id', $offer)->first();
        abort_unless($row !== null, 404);

        DB::table('smart_ordering_offer_rules')->where('id', $row->id)->update([
            ...$this->offerData($request, $site),
            'updated_at' => now(),
        ]);

        return response()->json($this->offerResource(
            DB::table('smart_ordering_offer_rules')->where('id', $row->id)->first(),
        ));
    }

    public function storeRecommendation(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $this->recommendationData($request, $site);

        $id = (int) DB::table('smart_ordering_recommendation_rules')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->id,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(
            $this->recommendationResource(DB::table('smart_ordering_recommendation_rules')->where('id', $id)->first()),
            201,
        );
    }

    public function updateRecommendation(
        Request $request,
        SmartOrderingSite $site,
        string $recommendation,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $row = DB::table('smart_ordering_recommendation_rules')
            ->where('site_id', $site->id)->where('public_id', $recommendation)->first();
        abort_unless($row !== null, 404);

        DB::table('smart_ordering_recommendation_rules')->where('id', $row->id)->update([
            ...$this->recommendationData($request, $site),
            'updated_at' => now(),
        ]);

        return response()->json($this->recommendationResource(
            DB::table('smart_ordering_recommendation_rules')->where('id', $row->id)->first(),
        ));
    }

    /** @return array<string,mixed> */
    private function offerData(Request $request, SmartOrderingSite $site): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(self::OFFER_TYPES)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'promotion_id' => ['nullable', 'integer', 'min:1'],
            'marketing_coupon_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'between:-100000,100000'],
            'stack_group' => ['nullable', 'string', 'max:80'],
            'exclusive' => ['nullable', 'boolean'],
            'minimum_basket' => ['nullable', 'numeric', 'min:0'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'placements' => ['nullable', 'array', 'max:6'],
            'placements.*' => [Rule::in(SmartOrderingMerchandisingService::PLACEMENTS)],
            'conditions' => ['nullable', 'array'],
            'effect' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertBranch($site, $data['branch_id'] ?? null);

        if (isset($data['promotion_id'])) {
            $valid = DB::table('promotions')
                ->where('id', (int) $data['promotion_id'])
                ->where(function ($query) use ($site): void {
                    $query->whereNull('store_id')->orWhere('store_id', $site->store_id);
                })->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['promotion_id' => ['Promotion is outside this restaurant scope.']]);
            }
        }

        if (isset($data['marketing_coupon_id'])) {
            $valid = DB::table('marketing_coupons')
                ->where('id', (int) $data['marketing_coupon_id'])
                ->where('scope_key', 'b2c:'.$site->store_id)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['marketing_coupon_id' => ['Coupon is outside this restaurant scope.']]);
            }
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'promotion_id' => $data['promotion_id'] ?? null,
            'marketing_coupon_id' => $data['marketing_coupon_id'] ?? null,
            'name' => trim((string) $data['name']),
            'type' => (string) $data['type'],
            'priority' => (int) ($data['priority'] ?? 100),
            'stack_group' => isset($data['stack_group']) ? trim((string) $data['stack_group']) : null,
            'exclusive' => (bool) ($data['exclusive'] ?? true),
            'minimum_basket' => isset($data['minimum_basket']) ? round((float) $data['minimum_basket'], 3) : null,
            'usage_limit_total' => $data['usage_limit_total'] ?? null,
            'placements' => $this->encoded((array) ($data['placements'] ?? [])),
            'conditions' => $this->encoded((array) ($data['conditions'] ?? [])),
            'effect' => $this->encoded((array) ($data['effect'] ?? [])),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /** @return array<string,mixed> */
    private function recommendationData(Request $request, SmartOrderingSite $site): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'placement' => ['required', Rule::in(SmartOrderingMerchandisingService::PLACEMENTS)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'trigger_product_id' => ['nullable', 'integer', 'min:1'],
            'recommended_product_id' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'between:-100000,100000'],
            'minimum_basket' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertBranch($site, $data['branch_id'] ?? null);
        foreach (['trigger_product_id', 'recommended_product_id'] as $field) {
            if (! isset($data[$field])) {
                continue;
            }
            $valid = DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->where('products.id', (int) $data[$field])
                ->where('catalogs.store_id', $site->store_id)
                ->where('catalogs.channel', 'b2c')
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages([$field => ['Product is outside this restaurant catalog.']]);
            }
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'name' => trim((string) $data['name']),
            'placement' => (string) $data['placement'],
            'trigger_product_id' => $data['trigger_product_id'] ?? null,
            'recommended_product_id' => (int) $data['recommended_product_id'],
            'priority' => (int) ($data['priority'] ?? 100),
            'minimum_basket' => isset($data['minimum_basket']) ? round((float) $data['minimum_basket'], 3) : null,
            'conditions' => $this->encoded((array) ($data['conditions'] ?? [])),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function assertBranch(SmartOrderingSite $site, mixed $branchId): void
    {
        if ($branchId === null) {
            return;
        }
        $valid = DB::table('smart_ordering_branches')
            ->where('site_id', $site->id)
            ->where('id', (int) $branchId)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['branch_id' => ['Branch is outside this restaurant scope.']]);
        }
    }

    /** @return array<string,mixed> */
    private function offerResource(?object $row): array
    {
        abort_unless($row !== null, 404);

        return [
            'id' => (string) $row->public_id,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            'promotion_id' => $row->promotion_id === null ? null : (int) $row->promotion_id,
            'marketing_coupon_id' => $row->marketing_coupon_id === null ? null : (int) $row->marketing_coupon_id,
            'priority' => (int) $row->priority,
            'stack_group' => $row->stack_group,
            'exclusive' => (bool) $row->exclusive,
            'minimum_basket' => $row->minimum_basket === null ? null : (float) $row->minimum_basket,
            'usage_limit_total' => $row->usage_limit_total === null ? null : (int) $row->usage_limit_total,
            'used_count' => (int) $row->used_count,
            'placements' => $this->decoded($row->placements),
            'conditions' => $this->decoded($row->conditions),
            'effect' => $this->decoded($row->effect),
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'is_active' => (bool) $row->is_active,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendationResource(?object $row): array
    {
        abort_unless($row !== null, 404);

        return [
            'id' => (string) $row->public_id,
            'name' => (string) $row->name,
            'placement' => (string) $row->placement,
            'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            'trigger_product_id' => $row->trigger_product_id === null ? null : (int) $row->trigger_product_id,
            'recommended_product_id' => (int) $row->recommended_product_id,
            'priority' => (int) $row->priority,
            'minimum_basket' => $row->minimum_basket === null ? null : (float) $row->minimum_basket,
            'conditions' => $this->decoded($row->conditions),
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'is_active' => (bool) $row->is_active,
        ];
    }

    private function encoded(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private function decoded(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            $permission,
            'b2c',
        );

        return $actor;
    }
}
