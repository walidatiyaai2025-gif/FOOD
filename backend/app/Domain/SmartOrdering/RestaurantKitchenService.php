<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RestaurantKitchenService
{
    /** @param Collection<int,SmartOrderingBranch> $branches
     * @return array<string,mixed>
     */
    public function workspace(Collection $branches, ?string $branchPublicId, User $actor): array
    {
        $selected = $branchPublicId
            ? $branches->firstWhere('public_id', $branchPublicId)
            : $branches->first();

        $empty = [
            'selected_branch' => $selected,
            'entitled' => false,
            'can_manage' => false,
            'routing_entitled' => false,
            'stations' => [],
            'rules' => [],
            'tickets' => [],
            'products' => [],
            'categories' => [],
        ];
        if (! $selected instanceof SmartOrderingBranch) {
            return $empty;
        }

        $storeId = (int) $selected->store_id;
        $canManage = $actor->hasPermission('orders.manage', $storeId) || $actor->hasPermission('orders.manage');
        $entitled = $this->isEntitled($selected, 'kitchen_display');
        $routingEntitled = $this->isEntitled($selected, 'kitchen_routing');

        if (! $entitled) {
            return [...$empty, 'selected_branch' => $selected, 'can_manage' => $canManage];
        }

        $stations = DB::table('smart_ordering_kitchen_stations')
            ->where('branch_id', $selected->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                ...((array) $row),
                'name' => app()->getLocale() === 'ar'
                    ? (string) ($row->name_ar ?: $row->name_en)
                    : (string) ($row->name_en ?: $row->name_ar),
                'active_ticket_count' => 0,
            ])
            ->values();

        $rules = [];
        if ($routingEntitled) {
            $rules = DB::table('smart_ordering_kitchen_routing_rules as r')
                ->join('smart_ordering_kitchen_stations as s', 's.id', '=', 'r.station_id')
                ->leftJoin('products as p', 'p.id', '=', 'r.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'r.category_id')
                ->where('r.branch_id', $selected->getKey())
                ->orderBy('r.priority')
                ->orderBy('r.id')
                ->get([
                    'r.id', 'r.public_id', 'r.station_id', 'r.match_type', 'r.product_id', 'r.category_id',
                    'r.priority', 'r.is_active', 's.name_en as station_name_en', 's.name_ar as station_name_ar',
                    'p.name as product_name', 'c.name as category_name',
                ])
                ->map(fn (object $row): array => [
                    ...((array) $row),
                    'station_name' => app()->getLocale() === 'ar'
                        ? (string) ($row->station_name_ar ?: $row->station_name_en)
                        : (string) ($row->station_name_en ?: $row->station_name_ar),
                    'match_label' => match ((string) $row->match_type) {
                        'product' => (string) ($row->product_name ?: '—'),
                        'category' => (string) ($row->category_name ?: '—'),
                        default => __('smart_ordering.kitchen.all_items'),
                    },
                ])
                ->values()
                ->all();
        }

        $products = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->join('catalogs as ca', 'ca.id', '=', 'c.catalog_id')
            ->where('ca.store_id', $storeId)
            ->where('p.is_active', true)
            ->orderBy('p.name')
            ->get(['p.id', 'p.name', 'c.name as category_name', 'c.id as category_id'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $categories = DB::table('categories as c')
            ->join('catalogs as ca', 'ca.id', '=', 'c.catalog_id')
            ->where('ca.store_id', $storeId)
            ->where('c.is_active', true)
            ->orderBy('c.name')
            ->get(['c.id', 'c.name'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $contexts = DB::table('smart_ordering_order_contexts as soc')
            ->join('orders as o', 'o.id', '=', 'soc.order_id')
            ->leftJoin('smart_ordering_kitchen_ticket_states as ks', 'ks.order_context_id', '=', 'soc.id')
            ->where('soc.branch_id', $selected->getKey())
            ->whereIn('soc.lifecycle_status', ['CONFIRMED', 'PREPARING', 'READY'])
            ->orderByDesc('soc.id')
            ->limit(80)
            ->get([
                'soc.id as context_id', 'soc.public_reference', 'soc.lifecycle_status', 'soc.fulfillment_mode',
                'soc.order_id', 'o.order_number', 'o.created_at', 'ks.id as kitchen_state_id',
                'ks.status as kitchen_status', 'ks.expedited_at',
            ]);

        $itemsByOrder = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('smart_ordering_kitchen_item_states as kis', 'kis.order_item_id', '=', 'oi.id')
            ->whereIn('oi.order_id', $contexts->pluck('order_id')->all())
            ->orderBy('oi.id')
            ->get([
                'oi.id as line_id', 'oi.order_id', 'oi.product_id', 'oi.name_snapshot', 'oi.quantity',
                'p.category_id', 'kis.status as kitchen_item_status',
            ])
            ->groupBy('order_id');

        $activeRules = collect($rules)->filter(fn (array $rule): bool => (bool) $rule['is_active'])->values();
        $stationById = $stations->keyBy('id');
        $stationLoads = [];

        $tickets = $contexts->map(function (object $context) use (
            $itemsByOrder,
            $activeRules,
            $stationById,
            $selected,
            &$stationLoads,
        ): array {
            $derived = match ((string) $context->lifecycle_status) {
                'READY' => 'ready',
                'PREPARING' => 'preparing',
                default => 'queued',
            };
            $status = (string) ($context->kitchen_status ?: $derived);
            $ageMinutes = (int) max(0, now()->diffInMinutes($context->created_at, true));
            $slaMinutes = match ((string) $context->fulfillment_mode) {
                'dine_in' => (int) $selected->dine_in_promise_minutes,
                'delivery' => (int) $selected->delivery_promise_minutes,
                default => (int) $selected->pickup_promise_minutes,
            };
            $slaState = $slaMinutes <= 0
                ? 'unconfigured'
                : ($ageMinutes > $slaMinutes ? 'overdue' : 'within');

            $routed = [];
            foreach ($itemsByOrder->get($context->order_id, collect()) as $item) {
                $rule = $activeRules->first(function (array $candidate) use ($item): bool {
                    return match ((string) $candidate['match_type']) {
                        'product' => (int) ($candidate['product_id'] ?? 0) === (int) $item->product_id,
                        'category' => (int) ($candidate['category_id'] ?? 0) === (int) $item->category_id,
                        'all' => true,
                        default => false,
                    };
                });
                $stationId = $rule ? (int) $rule['station_id'] : 0;
                $station = $stationById->get($stationId);
                $routeKey = $station === null ? 'unrouted' : (string) $stationId;
                $itemStatus = (string) ($item->kitchen_item_status ?: $status);

                $routed[$routeKey] ??= [
                    'station_id' => $station === null ? null : $stationId,
                    'name' => $station === null ? __('smart_ordering.kitchen.unrouted') : $station['name'],
                    'items' => [],
                ];
                $routed[$routeKey]['items'][] = [
                    'line_id' => (int) $item->line_id,
                    'name' => (string) $item->name_snapshot,
                    'quantity' => (float) $item->quantity,
                    'status' => $itemStatus,
                    'actions' => match ($itemStatus) {
                        'queued', 'preparing' => ['bump'],
                        'ready' => ['recall'],
                        default => [],
                    },
                    'operation_keys' => [
                        'bump' => (string) Str::uuid(),
                        'recall' => (string) Str::uuid(),
                    ],
                ];
            }

            foreach ($routed as $route) {
                if ($route['station_id'] !== null) {
                    $stationId = (int) $route['station_id'];
                    $stationLoads[$stationId] = ($stationLoads[$stationId] ?? 0) + 1;
                }
            }

            return [
                'reference' => (string) $context->public_reference,
                'order_number' => (string) $context->order_number,
                'canonical_status' => (string) $context->lifecycle_status,
                'kitchen_status' => $status,
                'expedited' => $context->expedited_at !== null,
                'age_minutes' => $ageMinutes,
                'sla_minutes' => $slaMinutes,
                'sla_state' => $slaState,
                'routes' => array_values($routed),
                'actions' => match ($status) {
                    'queued' => ['bump', 'expedite'],
                    'preparing' => ['bump', 'expedite'],
                    'ready' => ['recall'],
                    default => [],
                },
                'operation_keys' => [
                    'bump' => (string) Str::uuid(),
                    'recall' => (string) Str::uuid(),
                    'expedite' => (string) Str::uuid(),
                ],
            ];
        })->all();

        $stations = $stations->map(function (array $station) use ($stationLoads): array {
            $station['active_ticket_count'] = (int) ($stationLoads[(int) $station['id']] ?? 0);
            $station['capacity_state'] = $station['active_ticket_count'] >= (int) $station['capacity_limit']
                ? 'at_capacity'
                : 'available';

            return $station;
        })->all();

        return [
            'selected_branch' => $selected,
            'entitled' => true,
            'can_manage' => $canManage,
            'routing_entitled' => $routingEntitled,
            'stations' => $stations,
            'rules' => $rules,
            'tickets' => $tickets,
            'products' => $products,
            'categories' => $categories,
        ];
    }

    /** @param array<string,mixed> $data */
    public function createStation(SmartOrderingBranch $branch, array $data): void
    {
        DB::table('smart_ordering_kitchen_stations')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $branch->getKey(),
            'name_en' => trim((string) $data['name_en']),
            'name_ar' => isset($data['name_ar']) && trim((string) $data['name_ar']) !== '' ? trim((string) $data['name_ar']) : null,
            'capacity_limit' => (int) $data['capacity_limit'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function createRule(SmartOrderingBranch $branch, array $data): void
    {
        $station = DB::table('smart_ordering_kitchen_stations')
            ->where('branch_id', $branch->getKey())
            ->where('public_id', $data['station_public_id'])
            ->where('is_active', true)
            ->first();
        if ($station === null) {
            throw ValidationException::withMessages(['station_public_id' => [__('smart_ordering.kitchen.errors.station_invalid')]]);
        }

        $type = (string) $data['match_type'];
        $productId = $type === 'product' ? (int) ($data['product_id'] ?? 0) : null;
        $categoryId = $type === 'category' ? (int) ($data['category_id'] ?? 0) : null;
        $storeId = (int) $branch->store_id;

        if ($type === 'product' && ! $this->productBelongsToStore($productId, $storeId)) {
            throw ValidationException::withMessages(['product_id' => [__('smart_ordering.kitchen.errors.product_invalid')]]);
        }
        if ($type === 'category' && ! $this->categoryBelongsToStore($categoryId, $storeId)) {
            throw ValidationException::withMessages(['category_id' => [__('smart_ordering.kitchen.errors.category_invalid')]]);
        }

        DB::table('smart_ordering_kitchen_routing_rules')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $branch->getKey(),
            'station_id' => $station->id,
            'match_type' => $type,
            'product_id' => $productId,
            'category_id' => $categoryId,
            'priority' => (int) $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function deleteRule(SmartOrderingBranch $branch, string $rulePublicId): void
    {
        $deleted = DB::table('smart_ordering_kitchen_routing_rules')
            ->where('branch_id', $branch->getKey())
            ->where('public_id', $rulePublicId)
            ->delete();
        abort_unless($deleted === 1, 404);
    }

    public function applyTicketAction(
        SmartOrderingBranch $branch,
        string $reference,
        string $action,
        string $operationKey,
        User $actor,
    ): void {
        DB::transaction(function () use ($branch, $reference, $action, $operationKey, $actor): void {
            $context = DB::table('smart_ordering_order_contexts')
                ->where('branch_id', $branch->getKey())
                ->where('public_reference', $reference)
                ->lockForUpdate()
                ->first();
            abort_unless($context !== null, 404);
            abort_if(in_array((string) $context->lifecycle_status, ['COMPLETED', 'CANCELLED'], true), 409);

            $state = DB::table('smart_ordering_kitchen_ticket_states')
                ->where('order_context_id', $context->id)
                ->lockForUpdate()
                ->first();

            $existingEvent = DB::table('smart_ordering_kitchen_ticket_events')
                ->where('operation_key', $operationKey)
                ->first();
            if ($existingEvent !== null) {
                abort_unless(
                    $state !== null
                    && (int) $existingEvent->ticket_state_id === (int) $state->id
                    && $existingEvent->order_item_id === null,
                    409,
                );

                return;
            }

            $current = $state?->status ?: match ((string) $context->lifecycle_status) {
                'READY' => 'ready',
                'PREPARING' => 'preparing',
                default => 'queued',
            };
            $next = $current;
            $expeditedAt = $state?->expedited_at;

            if ($action === 'bump') {
                $next = match ($current) {
                    'queued' => 'preparing',
                    'preparing' => 'ready',
                    default => throw ValidationException::withMessages(['action' => [__('smart_ordering.kitchen.errors.transition_invalid')]]),
                };
            } elseif ($action === 'recall') {
                if ($current !== 'ready') {
                    throw ValidationException::withMessages(['action' => [__('smart_ordering.kitchen.errors.transition_invalid')]]);
                }
                $next = 'preparing';
            } elseif ($action === 'expedite') {
                if (! in_array($current, ['queued', 'preparing'], true)) {
                    throw ValidationException::withMessages(['action' => [__('smart_ordering.kitchen.errors.transition_invalid')]]);
                }
                $expeditedAt = $expeditedAt ?: now();
            } else {
                abort(404);
            }

            if ($state === null) {
                $stateId = (int) DB::table('smart_ordering_kitchen_ticket_states')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'order_context_id' => $context->id,
                    'status' => $next,
                    'expedited_at' => $expeditedAt,
                    'updated_by' => $actor->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $stateId = (int) $state->id;
                DB::table('smart_ordering_kitchen_ticket_states')->where('id', $stateId)->update([
                    'status' => $next,
                    'expedited_at' => $expeditedAt,
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('smart_ordering_kitchen_ticket_events')->insert([
                'ticket_state_id' => $stateId,
                'operation_key' => $operationKey,
                'action' => $action,
                'from_status' => $current,
                'to_status' => $next,
                'actor_id' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function applyItemAction(
        SmartOrderingBranch $branch,
        string $reference,
        int $lineId,
        string $action,
        string $operationKey,
        User $actor,
    ): void {
        DB::transaction(function () use ($branch, $reference, $lineId, $action, $operationKey, $actor): void {
            $context = DB::table('smart_ordering_order_contexts')
                ->where('branch_id', $branch->getKey())
                ->where('public_reference', $reference)
                ->lockForUpdate()
                ->first();
            abort_unless($context !== null, 404);
            abort_if(in_array((string) $context->lifecycle_status, ['COMPLETED', 'CANCELLED'], true), 409);

            $line = DB::table('order_items')
                ->where('id', $lineId)
                ->where('order_id', $context->order_id)
                ->lockForUpdate()
                ->first();
            abort_unless($line !== null, 404);

            $ticket = DB::table('smart_ordering_kitchen_ticket_states')
                ->where('order_context_id', $context->id)
                ->lockForUpdate()
                ->first();

            if ($ticket === null) {
                $ticketStatus = match ((string) $context->lifecycle_status) {
                    'READY' => 'ready',
                    'PREPARING' => 'preparing',
                    default => 'queued',
                };
                $ticketId = (int) DB::table('smart_ordering_kitchen_ticket_states')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'order_context_id' => $context->id,
                    'status' => $ticketStatus,
                    'expedited_at' => null,
                    'updated_by' => $actor->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $ticketId = (int) $ticket->id;
                $ticketStatus = (string) $ticket->status;
            }

            $existingEvent = DB::table('smart_ordering_kitchen_ticket_events')
                ->where('operation_key', $operationKey)
                ->first();
            if ($existingEvent !== null) {
                abort_unless(
                    (int) $existingEvent->ticket_state_id === $ticketId
                    && (int) ($existingEvent->order_item_id ?? 0) === $lineId,
                    409,
                );

                return;
            }

            $itemState = DB::table('smart_ordering_kitchen_item_states')
                ->where('order_item_id', $lineId)
                ->lockForUpdate()
                ->first();
            $current = (string) ($itemState?->status ?: $ticketStatus);
            $next = $this->nextPreparationStatus($current, $action);

            if ($itemState === null) {
                DB::table('smart_ordering_kitchen_item_states')->insert([
                    'public_id' => (string) Str::uuid(),
                    'ticket_state_id' => $ticketId,
                    'order_item_id' => $lineId,
                    'status' => $next,
                    'updated_by' => $actor->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('smart_ordering_kitchen_item_states')
                    ->where('id', $itemState->id)
                    ->update([
                        'status' => $next,
                        'updated_by' => $actor->getKey(),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('smart_ordering_kitchen_ticket_events')->insert([
                'ticket_state_id' => $ticketId,
                'operation_key' => $operationKey,
                'order_item_id' => $lineId,
                'action' => 'item_'.$action,
                'from_status' => $current,
                'to_status' => $next,
                'actor_id' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function nextPreparationStatus(string $current, string $action): string
    {
        if ($action === 'bump') {
            return match ($current) {
                'queued' => 'preparing',
                'preparing' => 'ready',
                default => throw ValidationException::withMessages([
                    'action' => [__('smart_ordering.kitchen.errors.transition_invalid')],
                ]),
            };
        }

        if ($action === 'recall' && $current === 'ready') {
            return 'preparing';
        }

        throw ValidationException::withMessages([
            'action' => [__('smart_ordering.kitchen.errors.transition_invalid')],
        ]);
    }

    public function isEntitled(SmartOrderingBranch $branch, string $module = 'kitchen_display'): bool
    {
        return (bool) (DB::table('smart_ordering_entitlements')
            ->where('site_id', $branch->site_id)
            ->whereNull('branch_id')
            ->where('scope_key', 'site')
            ->where('module', $module)
            ->value('enabled') ?? false);
    }

    private function productBelongsToStore(int $productId, int $storeId): bool
    {
        return DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->join('catalogs as ca', 'ca.id', '=', 'c.catalog_id')
            ->where('p.id', $productId)
            ->where('ca.store_id', $storeId)
            ->exists();
    }

    private function categoryBelongsToStore(int $categoryId, int $storeId): bool
    {
        return DB::table('categories as c')
            ->join('catalogs as ca', 'ca.id', '=', 'c.catalog_id')
            ->where('c.id', $categoryId)
            ->where('ca.store_id', $storeId)
            ->exists();
    }
}
