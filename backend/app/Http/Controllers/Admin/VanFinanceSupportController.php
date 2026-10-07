<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FieldOperationsFinanceService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

final class VanFinanceSupportController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly FieldOperationsFinanceService $finance,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasRole('SUPER_ADMIN') || $user->hasPermission('finance.view'), 403);

        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $filters = $request->validate([
            'ops_tab' => ['nullable', 'string', 'in:wallets,collections,remittances,reconciliation'],
            'ops_q' => ['nullable', 'string', 'max:120'],
            'ops_status' => ['nullable', 'string', 'max:32'],
            'ops_per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'ops_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $storeIds = $this->authorizedB2bStoreIds($user);
        $finance = $this->finance->viewModel($storeIds, $filters);
        $finance['rows'] = collect($finance['rows'])
            ->map(fn (array $row): array => [
                ...$row,
                'actor_display' => $this->actorDisplay(
                    (string) ($row['actor_type'] ?? ''),
                    (int) ($row['actor_id'] ?? 0),
                ),
                'store_display' => trim((string) ($row['store_name'] ?? '')),
            ])
            ->all();

        return view('admin.van-finance-support', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'administration_hub',
            'finance' => $finance,
        ]);
    }

    /** @return list<int> */
    private function authorizedB2bStoreIds(User $user): array
    {
        $base = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B');

        if ($user->hasRole('SUPER_ADMIN')) {
            return $base->pluck('stores.id')->map(fn ($id): int => (int) $id)->all();
        }

        $hasGlobalFinance = $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereHas('permissions', fn ($query) => $query->where('permissions.code', 'finance.view'))
            ->exists();

        if ($hasGlobalFinance) {
            return $base->pluck('stores.id')->map(fn ($id): int => (int) $id)->all();
        }

        return DB::table('user_store_roles')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('user_store_roles.user_id', $user->getKey())
            ->where('roles.is_active', true)
            ->where('permissions.code', 'finance.view')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->pluck('stores.id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function actorDisplay(string $type, int $id): string
    {
        return match (strtolower($type)) {
            'van' => $this->vanDisplay($id),
            'driver' => $this->driverDisplay($id),
            'user', 'representative' => $this->userDisplay($id),
            default => __('van_finance_support.actor_unknown'),
        };
    }

    private function vanDisplay(int $id): string
    {
        $van = DB::table('vans')->where('id', $id)->first(['code', 'plate_number']);

        if ($van === null) {
            return __('van_finance_support.actor_van');
        }

        return trim((string) $van->code.($van->plate_number ? ' · '.$van->plate_number : ''));
    }

    private function driverDisplay(int $id): string
    {
        $driver = DB::table('drivers')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.id', $id)
            ->first(['users.name', 'users.email']);

        if ($driver === null) {
            return __('van_finance_support.actor_driver');
        }

        return trim((string) ($driver->name ?: $driver->email ?: __('van_finance_support.actor_driver')));
    }

    private function userDisplay(int $id): string
    {
        $user = DB::table('users')->where('id', $id)->first(['name', 'email']);

        if ($user === null) {
            return __('van_finance_support.actor_operator');
        }

        return trim((string) ($user->name ?: $user->email ?: __('van_finance_support.actor_operator')));
    }
}
