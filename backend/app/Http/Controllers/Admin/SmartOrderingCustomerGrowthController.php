<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\FeedbackService;
use App\Domain\SmartOrdering\LoyaltyAdminService;
use App\Domain\SmartOrdering\LoyaltyService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\RepeatOrderService;
use App\Domain\SmartOrdering\RestaurantCustomerService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SmartOrderingCustomerGrowthController extends Controller
{
    public function profile(
        Request $request,
        SmartOrderingSite $site,
        int $customer,
        RestaurantCustomerService $customers,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        return response()->json($customers->profile($site, $customer));
    }

    public function history(
        Request $request,
        SmartOrderingSite $site,
        int $customer,
        RestaurantCustomerService $customers,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        return response()->json([
            'orders' => $customers->orderHistory($site, $customer, $request->integer('limit', 20)),
        ]);
    }

    public function repeat(
        Request $request,
        SmartOrderingSite $site,
        int $customer,
        int $order,
        RepeatOrderService $repeat,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'order_item_ids' => ['nullable', 'array', 'max:100'],
            'order_item_ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json(
            $repeat->createDraft($site, $customer, $order, array_values((array) ($data['order_item_ids'] ?? []))),
            201,
        );
    }

    public function loyaltyBalance(
        Request $request,
        SmartOrderingSite $site,
        int $customer,
        LoyaltyService $loyalty,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        return response()->json($loyalty->balance($site, $customer));
    }

    public function loyaltyAdjust(
        Request $request,
        SmartOrderingSite $site,
        int $customer,
        LoyaltyService $loyalty,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($loyalty->adjust(
            $actor,
            $site,
            $customer,
            (int) $data['points'],
            (string) $data['reason'],
        ));
    }

    public function loyaltyRule(
        Request $request,
        SmartOrderingSite $site,
        LoyaltyAdminService $admin,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'points_per_currency_unit' => ['required', 'numeric', 'min:0'],
            'minimum_spend' => ['nullable', 'numeric', 'min:0'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json($admin->configureRule(
            $actor,
            $site,
            (float) $data['points_per_currency_unit'],
            (float) ($data['minimum_spend'] ?? 0),
            isset($data['expiry_days']) ? (int) $data['expiry_days'] : null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $request->boolean('is_active', true),
        ));
    }

    public function loyaltyRewards(
        Request $request,
        SmartOrderingSite $site,
        LoyaltyAdminService $admin,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        return response()->json([
            'rewards' => $admin->rewards($site, $request->integer('branch_id') ?: null),
        ]);
    }

    public function loyaltyRewardStore(
        Request $request,
        SmartOrderingSite $site,
        LoyaltyAdminService $admin,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'payload' => ['nullable', 'array'],
        ]);

        return response()->json($admin->createReward(
            $actor,
            $site,
            (string) $data['name_en'],
            $data['name_ar'] ?? null,
            (int) $data['points_cost'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (array) ($data['payload'] ?? []),
        ), 201);
    }

    public function loyaltyRewardUpdate(
        Request $request,
        SmartOrderingSite $site,
        string $reward,
        LoyaltyAdminService $admin,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'payload' => ['nullable', 'array'],
        ]);

        return response()->json($admin->updateReward(
            $actor,
            $site,
            $reward,
            (string) $data['name_en'],
            $data['name_ar'] ?? null,
            (int) $data['points_cost'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (bool) $data['is_active'],
            (array) ($data['payload'] ?? []),
        ));
    }

    public function reviewSettings(
        Request $request,
        SmartOrderingSite $site,
        FeedbackService $feedback,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'request_enabled' => ['nullable', 'boolean'],
            'low_rating_threshold' => ['required', 'integer', 'between:1,5'],
            'external_review_min_rating' => ['required', 'integer', 'between:1,5'],
            'external_review_url' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($feedback->configure(
            $actor,
            $site,
            $request->boolean('request_enabled', true),
            (int) $data['low_rating_threshold'],
            (int) $data['external_review_min_rating'],
            $data['external_review_url'] ?? null,
        ));
    }

    public function recoveryCases(
        Request $request,
        SmartOrderingSite $site,
        FeedbackService $feedback,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:OPEN,RESOLVED'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'cases' => $feedback->recoveryCases(
                $site,
                (string) ($data['status'] ?? 'OPEN'),
                (int) ($data['limit'] ?? 50),
            ),
        ]);
    }

    public function resolveRecovery(
        Request $request,
        SmartOrderingSite $site,
        string $case,
        FeedbackService $feedback,
        TenantContextResolver $tenants,
    ): JsonResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:5000']]);
        $feedback->resolveCase($actor, $site, $case, (string) $data['resolution_note']);

        return response()->json(['status' => 'RESOLVED']);
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
