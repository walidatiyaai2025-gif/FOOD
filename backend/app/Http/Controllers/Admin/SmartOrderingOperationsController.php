<?php

namespace App\Http\Controllers\Admin;

use App\Domain\SmartOrdering\LifecycleService;
use App\Http\Controllers\Controller;
use App\Models\SmartOrderingBranch;
use App\Models\SmartOrderingOrderContext;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SmartOrderingOperationsController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AdminNavigation $navigation,
        private readonly LifecycleService $lifecycle,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $storeIds = $this->scope->allowedStoreIds($actor, 'orders.view', 'b2c');
        abort_if($storeIds === [], 403);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['NEW', 'CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'])],
            'fulfillment_mode' => ['nullable', Rule::in(['dine_in', 'pickup', 'delivery'])],
            'branch' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tab' => ['nullable', Rule::in(['orders', 'branches'])],
        ]);

        $query = DB::table('smart_ordering_order_contexts as soc')
            ->join('orders as o', 'o.id', '=', 'soc.order_id')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
            ->whereIn('o.store_id', $storeIds)
            ->when(isset($validated['status']), fn ($q) => $q->where('soc.lifecycle_status', $validated['status']))
            ->when(isset($validated['fulfillment_mode']), fn ($q) => $q->where('soc.fulfillment_mode', $validated['fulfillment_mode']))
            ->when(isset($validated['branch']), fn ($q) => $q->where('b.public_id', $validated['branch']))
            ->when(isset($validated['from']), fn ($q) => $q->whereDate('o.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->whereDate('o.created_at', '<=', $validated['to']))
            ->orderByDesc('o.id')
            ->select([
                'soc.id as context_id',
                'soc.public_reference',
                'soc.lifecycle_status',
                'soc.fulfillment_mode',
                'soc.scheduled_for',
                'soc.notification_status',
                'soc.grand_total',
                'o.id as order_id',
                'o.order_number',
                'o.store_id',
                'o.currency',
                'o.created_at',
                'b.public_id as branch_public_id',
                'b.name as branch_name',
            ]);

        $orders = $query->paginate(25)->withQueryString();
        $rows = collect($orders->items())->map(function (object $row) use ($actor): array {
            return [
                ...((array) $row),
                'can_manage' => $actor->hasPermission('orders.manage', (int) $row->store_id)
                    || $actor->hasPermission('orders.manage'),
                'allowed_targets' => $this->lifecycle->allowedTargets(
                    (string) $row->lifecycle_status,
                    (string) $row->fulfillment_mode,
                ),
            ];
        })->all();

        $branches = SmartOrderingBranch::query()
            ->whereIn('store_id', $storeIds)
            ->orderBy('name')
            ->get();
        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.smart-ordering', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'smart_ordering',
            'orders' => $orders,
            'rows' => $rows,
            'branches' => $branches,
            'stores' => $stores,
            'tab' => $validated['tab'] ?? 'orders',
            'isAr' => app()->getLocale() === 'ar',
        ]);
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->branchData($request);
        $storeId = (int) $data['store_id'];
        $this->scope->assertStore($actor, $storeId, 'orders.manage', 'b2c');

        $branch = SmartOrderingBranch::query()->create([
            ...$this->branchAttributes($data),
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
        ]);
        $this->syncHours($branch, (array) ($data['hours'] ?? []));

        return redirect()->route('admin.smart-ordering.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.branch_saved'));
    }

    public function updateBranch(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->scope->assertStore($actor, (int) $model->store_id, 'orders.manage', 'b2c');

        $data = $this->branchData($request, false);
        $model->update($this->branchAttributes($data));
        $this->syncHours($model, (array) ($data['hours'] ?? []));

        return redirect()->route('admin.smart-ordering.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.branch_saved'));
    }

    public function storeZone(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->scope->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');
        $data = $this->zoneData($request);

        DB::table('smart_ordering_delivery_zones')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $branchModel->getKey(),
            ...$this->zoneAttributes($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.smart-ordering.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_saved'));
    }

    public function updateZone(string $branch, string $zone, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->scope->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');
        $zoneModel = DB::table('smart_ordering_delivery_zones')
            ->where('public_id', $zone)
            ->where('branch_id', $branchModel->getKey())
            ->first();
        abort_unless($zoneModel !== null, 404);

        DB::table('smart_ordering_delivery_zones')
            ->where('id', $zoneModel->id)
            ->update([
                ...$this->zoneAttributes($this->zoneData($request)),
                'updated_at' => now(),
            ]);

        return redirect()->route('admin.smart-ordering.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_saved'));
    }

    public function destroyZone(string $branch, string $zone, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->scope->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');

        $deleted = DB::table('smart_ordering_delivery_zones')
            ->where('public_id', $zone)
            ->where('branch_id', $branchModel->getKey())
            ->delete();
        abort_unless($deleted === 1, 404);

        return redirect()->route('admin.smart-ordering.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_deleted'));
    }

    public function transition(string $reference, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $context = SmartOrderingOrderContext::query()
            ->where('public_reference', $reference)
            ->firstOrFail();
        $order = DB::table('orders')->where('id', $context->order_id)->first(['store_id']);
        abort_unless($order !== null, 404);
        $this->scope->assertStore($actor, (int) $order->store_id, 'orders.manage', 'b2c');

        $data = $request->validate([
            'status' => ['required', Rule::in(['CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'transition_key' => ['nullable', 'string', 'max:100'],
        ]);

        $this->lifecycle->transition(
            $context,
            $actor,
            (string) $data['status'],
            isset($data['note']) ? (string) $data['note'] : null,
            isset($data['transition_key']) ? (string) $data['transition_key'] : null,
        );

        return redirect()->back()->with('status', __('smart_ordering.status_updated'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function branchData(Request $request, bool $withStore = true): array
    {
        return $request->validate([
            'store_id' => [$withStore ? 'required' : 'sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['nullable', 'boolean'],
            'accepting_orders' => ['nullable', 'boolean'],
            'supports_dine_in' => ['nullable', 'boolean'],
            'supports_pickup' => ['nullable', 'boolean'],
            'supports_delivery' => ['nullable', 'boolean'],
            'allow_scheduled' => ['nullable', 'boolean'],
            'pickup_lead_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'max_schedule_days' => ['required', 'integer', 'min:1', 'max:90'],
            'service_charge' => ['required', 'numeric', 'min:0', 'max:999999'],
            'whatsapp_routing_identity' => ['nullable', 'string', 'max:190'],
            'hours' => ['nullable', 'array'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function zoneData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'center_latitude' => ['required', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:999999'],
            'minimum_order' => ['required', 'numeric', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function zoneAttributes(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'center_latitude' => round((float) $data['center_latitude'], 7),
            'center_longitude' => round((float) $data['center_longitude'], 7),
            'radius_km' => round((float) $data['radius_km'], 3),
            'delivery_fee' => round((float) $data['delivery_fee'], 3),
            'minimum_order' => round((float) $data['minimum_order'], 3),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function branchAttributes(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'timezone' => (string) $data['timezone'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'accepting_orders' => (bool) ($data['accepting_orders'] ?? false),
            'supports_dine_in' => (bool) ($data['supports_dine_in'] ?? false),
            'supports_pickup' => (bool) ($data['supports_pickup'] ?? false),
            'supports_delivery' => (bool) ($data['supports_delivery'] ?? false),
            'allow_scheduled' => (bool) ($data['allow_scheduled'] ?? false),
            'pickup_lead_minutes' => (int) $data['pickup_lead_minutes'],
            'max_schedule_days' => (int) $data['max_schedule_days'],
            'service_charge' => round((float) $data['service_charge'], 3),
            'whatsapp_routing_identity' => trim((string) ($data['whatsapp_routing_identity'] ?? '')) ?: null,
        ];
    }

    /** @param array<int|string,mixed> $hours */
    private function syncHours(SmartOrderingBranch $branch, array $hours): void
    {
        foreach ($hours as $day => $settings) {
            $day = (int) $day;
            if ($day < 0 || $day > 6 || ! is_array($settings)) {
                continue;
            }
            DB::table('smart_ordering_branch_hours')->updateOrInsert(
                ['branch_id' => $branch->getKey(), 'day_of_week' => $day],
                [
                    'opens_at' => $settings['opens_at'] ?? null,
                    'closes_at' => $settings['closes_at'] ?? null,
                    'is_closed' => (bool) ($settings['is_closed'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
