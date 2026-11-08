<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class CustomerGroupManagementController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        [$actor, $storeId] = $this->authorizedStore($request, 'customers.view');
        $search = trim((string) $request->query('q', ''));
        $groups = DB::table('commercial_customer_groups')
            ->where('store_id', $storeId)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderByDesc('priority')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        $counts = DB::table('commercial_customer_group_members')
            ->select('customer_group_id')
            ->selectRaw('COUNT(*) as member_count')
            ->whereIn('customer_group_id', $groups->pluck('id')->all())
            ->groupBy('customer_group_id')
            ->pluck('member_count', 'customer_group_id');

        $legacyIds = DB::table('b2b_customers')->whereNotNull('legacy_customer_id')
            ->pluck('legacy_customer_id')->map(static fn ($id): int => (int) $id)->all();
        $customers = DB::table('customers')
            ->whereIn('id', $legacyIds)
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email']);

        $members = DB::table('commercial_customer_group_members')
            ->whereIn('customer_group_id', $groups->pluck('id')->all())
            ->get(['customer_group_id', 'customer_id'])
            ->groupBy('customer_group_id');

        return view('admin.customer-groups', [
            'user' => $actor,
            'storeId' => $storeId,
            'groups' => $groups,
            'counts' => $counts,
            'customers' => $customers,
            'members' => $members,
            'canManage' => in_array($storeId, $this->scope->allowedStoreIds($actor, 'catalog.manage', 'b2b'), true),
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'b2b_customers',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$actor, $storeId] = $this->authorizedStore($request, 'catalog.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
        ]);
        DB::transaction(function () use ($actor, $storeId, $data, $request): void {
            $groupId = DB::table('commercial_customer_groups')->insertGetId([
                'store_id' => $storeId,
                'name' => trim($data['name']),
                'priority' => (int) ($data['priority'] ?? 0),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('customers.group.created', $actor, null, null, [
                'store_id' => $storeId, 'group_id' => $groupId, 'name' => trim($data['name']),
            ], $request);
        });

        return back()->with('status', __('customer_groups.saved'));
    }

    public function update(Request $request, int $group): RedirectResponse
    {
        [$actor, $storeId] = $this->authorizedStore($request, 'catalog.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
            'is_active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($actor, $storeId, $group, $data, $request): void {
            $before = DB::table('commercial_customer_groups')
                ->where('id', $group)->where('store_id', $storeId)
                ->lockForUpdate()->first();
            abort_if($before === null, 404);
            $after = [
                'store_id' => $storeId,
                'group_id' => $group,
                'name' => trim($data['name']),
                'priority' => (int) ($data['priority'] ?? 0),
                'is_active' => (bool) $data['is_active'],
            ];
            DB::table('commercial_customer_groups')
                ->where('id', $group)->where('store_id', $storeId)
                ->update([
                    'name' => $after['name'],
                    'priority' => $after['priority'],
                    'is_active' => $after['is_active'],
                    'updated_at' => now(),
                ]);
            $this->audit->record('customers.group.updated', $actor, null, [
                'store_id' => $storeId, 'group_id' => $group,
                'name' => $before->name, 'priority' => $before->priority,
                'is_active' => $before->is_active,
            ], $after, $request);
        });

        return back()->with('status', __('customer_groups.saved'));
    }

    public function members(Request $request, int $group): RedirectResponse
    {
        [$actor, $storeId] = $this->authorizedStore($request, 'catalog.manage');
        $data = $request->validate([
            'customer_ids' => ['sometimes', 'array', 'max:300'],
            'customer_ids.*' => ['required', 'integer', 'distinct', 'exists:customers,id'],
            'operation' => ['required', 'in:add,remove'],
        ]);
        $ids = array_map('intval', $data['customer_ids'] ?? []);
        DB::transaction(function () use ($actor, $storeId, $group, $ids, $data, $request): void {
            $target = DB::table('commercial_customer_groups')
                ->where('id', $group)->where('store_id', $storeId)->lockForUpdate()->first(['id']);
            abort_if($target === null, 404);

            $allowedIds = DB::table('b2b_customers')
                ->whereIn('legacy_customer_id', $ids)
                ->pluck('legacy_customer_id')
                ->map(static fn ($id): int => (int) $id)->all();
            if (count(array_unique($allowedIds)) !== count($ids)) {
                throw ValidationException::withMessages([
                    'customer_ids' => [__('customer_groups.customer_scope_error')],
                ]);
            }

            $previousMemberIds = DB::table('commercial_customer_group_members')
                ->where('customer_group_id', $group)->orderBy('customer_id')
                ->pluck('customer_id')->map(static fn ($id): int => (int) $id)->all();

            if ($data['operation'] === 'remove') {
                DB::table('commercial_customer_group_members')
                    ->where('customer_group_id', $group)->whereIn('customer_id', $ids)->delete();
            } else {
                $rows = array_map(static fn (int $id): array => [
                    'customer_group_id' => $group,
                    'customer_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $ids);
                if ($rows !== []) {
                    DB::table('commercial_customer_group_members')->insertOrIgnore($rows);
                }
            }

            $nextMemberIds = DB::table('commercial_customer_group_members')
                ->where('customer_group_id', $group)->orderBy('customer_id')
                ->pluck('customer_id')->map(static fn ($id): int => (int) $id)->all();
            if ($previousMemberIds !== $nextMemberIds) {
                $this->audit->record('customers.group.members_updated', $actor, null, [
                    'store_id' => $storeId,
                    'group_id' => $group,
                    'customer_ids' => $previousMemberIds,
                ], [
                    'store_id' => $storeId,
                    'group_id' => $group,
                    'customer_ids' => $nextMemberIds,
                    'operation' => $data['operation'],
                ], $request);
            }
        }, 3);

        return back()->with('status', __('customer_groups.members_saved'));
    }

    /** @return array{User,int} */
    private function authorizedStore(Request $request, string $permission): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeId = $request->integer('store_id');
        if ($storeId <= 0) {
            $storeId = (int) ($this->scope->allowedStoreIds($actor, $permission, 'b2b')[0] ?? 0);
        }
        abort_unless($storeId > 0, 404);
        $this->scope->assertStore($actor, $storeId, $permission, 'b2b');

        return [$actor, $storeId];
    }
}
