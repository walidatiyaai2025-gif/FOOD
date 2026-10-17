<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantKitchenService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingKitchenController extends Controller
{
    public function __construct(
        private readonly RestaurantSaasStoreAccess $scope,
        private readonly RestaurantKitchenService $kitchen,
    ) {}

    public function storeStation(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->branch($branch, $actor);
        $this->assertEntitled($model);

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['nullable', 'string', 'max:160'],
            'capacity_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $this->kitchen->createStation($model, $data);

        return $this->back($model)->with('status', __('smart_ordering.kitchen.station_saved'));
    }

    public function storeRule(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->branch($branch, $actor);
        $this->assertEntitled($model, 'kitchen_routing');

        $data = $request->validate([
            'station_public_id' => ['required', 'uuid'],
            'match_type' => ['required', Rule::in(['all', 'product', 'category'])],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $this->kitchen->createRule($model, $data);

        return $this->back($model)->with('status', __('smart_ordering.kitchen.rule_saved'));
    }

    public function destroyRule(string $branch, string $rule, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->branch($branch, $actor);
        $this->assertEntitled($model, 'kitchen_routing');
        $this->kitchen->deleteRule($model, $rule);

        return $this->back($model)->with('status', __('smart_ordering.kitchen.rule_deleted'));
    }

    public function ticketAction(string $branch, string $reference, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->branch($branch, $actor);
        $this->assertEntitled($model);

        $data = $request->validate([
            'action' => ['required', Rule::in(['bump', 'recall', 'expedite'])],
            'operation_key' => ['required', 'uuid'],
        ]);
        $this->kitchen->applyTicketAction(
            $model,
            $reference,
            (string) $data['action'],
            (string) $data['operation_key'],
            $actor,
        );

        return $this->back($model)->with('status', __('smart_ordering.kitchen.ticket_updated'));
    }

    public function itemAction(string $branch, string $reference, int $line, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->branch($branch, $actor);
        $this->assertEntitled($model);

        $data = $request->validate([
            'action' => ['required', Rule::in(['bump', 'recall'])],
            'operation_key' => ['required', 'uuid'],
        ]);
        $this->kitchen->applyItemAction(
            $model,
            $reference,
            $line,
            (string) $data['action'],
            (string) $data['operation_key'],
            $actor,
        );

        return $this->back($model)->with('status', __('smart_ordering.kitchen.item_updated'));
    }

    private function branch(string $publicId, User $actor): SmartOrderingBranch
    {
        $branch = SmartOrderingBranch::query()
            ->with('site')
            ->where('public_id', $publicId)
            ->firstOrFail();
        $this->scope->assertStore($actor, (int) $branch->store_id, 'orders.manage', 'b2c');

        return $branch;
    }

    private function assertEntitled(SmartOrderingBranch $branch, string $module = 'kitchen_display'): void
    {
        abort_unless($this->kitchen->isEntitled($branch, $module), 403);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function back(SmartOrderingBranch $branch): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.operations.index', [
            'tab' => 'kitchen',
            'branch' => $branch->public_id,
        ]);
    }
}
