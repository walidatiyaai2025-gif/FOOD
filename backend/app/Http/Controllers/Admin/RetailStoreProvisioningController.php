<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RetailWholesaleAccountService;
use App\Services\StoreLogoService;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class RetailStoreProvisioningController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContextResolver $tenantContext,
        private readonly RetailWholesaleAccountService $wholesaleAccounts,
        private readonly StoreLogoService $logos,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->superAdmin($request);
        $search = trim((string) $request->query('q', ''));

        $stores = Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('stores.name', 'like', "%{$search}%")
                    ->orWhere('stores.code', 'like', "%{$search}%");
            }))
            ->with(['storeRoleAssignments.user', 'storeRoleAssignments.role'])
            ->orderByDesc('stores.created_at')
            ->orderByDesc('stores.id')
            ->paginate(25)
            ->withQueryString();

        $storeIds = $stores->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $commerceByStore = DB::table('retail_wholesale_accounts')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
            ->leftJoin('users as owner_user', 'owner_user.id', '=', 'retail_wholesale_accounts.owner_user_id')
            ->leftJoin('platform_customers as owner_platform_customer', 'owner_platform_customer.user_id', '=', 'owner_user.id')
            ->whereIn('retail_wholesale_accounts.retail_store_id', $storeIds)
            ->get([
                'retail_wholesale_accounts.retail_store_id',
                'retail_wholesale_accounts.b2b_customer_id',
                'retail_wholesale_accounts.owner_user_id',
                'b2b_accounts.id as wholesale_account_id',
                'b2b_accounts.status as wholesale_account_status',
                'b2b_accounts.price_tier_id',
                'b2b_price_tiers.name as price_tier_name',
                'owner_user.name as owner_name',
                'owner_user.email as owner_email',
                'owner_user.is_active as owner_user_active',
                'owner_platform_customer.id as owner_platform_customer_id',
                'owner_platform_customer.is_active as owner_platform_customer_active',
            ])
            ->keyBy('retail_store_id');

        $priceTiers = DB::table('b2b_price_tiers')
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $priceTierById = $priceTiers->keyBy('id');

        $stores->getCollection()->transform(function (Store $store) use ($commerceByStore, $priceTierById): Store {
            $commerce = $commerceByStore->get($store->id);
            $store->setAttribute(
                'wholesale_price_tier_id',
                $commerce?->price_tier_id === null ? null : (int) $commerce->price_tier_id,
            );
            $store->setAttribute('wholesale_price_tier_name', $commerce?->price_tier_name);
            $store->setAttribute(
                'wholesale_b2b_customer_id',
                $commerce?->b2b_customer_id === null ? null : (int) $commerce->b2b_customer_id,
            );
            $store->setAttribute(
                'wholesale_account_id',
                $commerce?->wholesale_account_id === null ? null : (int) $commerce->wholesale_account_id,
            );
            $store->setAttribute('wholesale_account_status', $commerce?->wholesale_account_status);
            $store->setAttribute(
                'primary_owner_user_id',
                $commerce?->owner_user_id === null ? null : (int) $commerce->owner_user_id,
            );
            $store->setAttribute('primary_owner_name', $commerce?->owner_name);
            $store->setAttribute('primary_owner_email', $commerce?->owner_email);
            $store->setAttribute('primary_owner_active', (bool) ($commerce->owner_user_active ?? false));
            $store->setAttribute(
                'customer_app_identity_linked',
                $commerce?->owner_platform_customer_id !== null
                    && (bool) ($commerce->owner_platform_customer_active ?? false),
            );
            $store->setAttribute('wholesale_account_linked', $commerce?->wholesale_account_id !== null);

            $customerTierId = $store->default_customer_wholesale_price_tier_id;
            $customerTier = $customerTierId === null ? null : $priceTierById->get((int) $customerTierId);
            $store->setAttribute('customer_wholesale_price_tier_name', $customerTier?->name);

            return $store;
        });

        return view('admin.retail-stores', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'retail_store_provisioning',
            'stores' => $stores,
            'search' => $search,
            'storeRoles' => Role::query()->where('is_active', true)->where('scope', 'store')->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'priceTiers' => $priceTiers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:stores,code'],
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'price_tier_id' => ['required', 'integer', 'exists:b2b_price_tiers,id'],
            'default_customer_wholesale_price_tier_id' => ['nullable', 'integer', 'exists:b2b_price_tiers,id'],
            'is_active' => ['nullable', 'boolean'],
            'advertising_enabled' => ['nullable', 'boolean'],
            'live_ads_enabled' => ['nullable', 'boolean'],
            'coupons_enabled' => ['nullable', 'boolean'],
            'manager_mode' => ['required', Rule::in(['existing', 'new'])],
            'manager_user_id' => ['nullable', 'required_if:manager_mode,existing', 'integer', 'exists:users,id'],
            'manager_name' => ['nullable', 'required_if:manager_mode,new', 'string', 'max:255'],
            'manager_email' => ['nullable', 'required_if:manager_mode,new', 'email', 'max:255', 'unique:users,email'],
            'manager_password' => ['nullable', 'required_if:manager_mode,new', 'string', 'min:8', 'max:255'],
        ]);

        $store = DB::transaction(function () use ($request, $data, $actor): Store {
            $storeTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
            abort_if($storeTypeId === null, 500, 'Retail store type is not configured.');

            $store = Store::query()->create([
                'store_type_id' => $storeTypeId,
                'default_customer_wholesale_price_tier_id' => $data['default_customer_wholesale_price_tier_id'] ?? null,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'logo_path' => null,
                'is_active' => $request->boolean('is_active', true),
                'advertising_enabled' => $request->boolean('advertising_enabled', true),
                'live_ads_enabled' => $request->boolean('live_ads_enabled', true),
                'coupons_enabled' => $request->boolean('coupons_enabled', true),
            ]);
            $logo = $request->file('logo');
            abort_unless($logo instanceof UploadedFile, 422, 'Store logo is required.');
            $store->update(['logo_path' => $this->logos->store($logo, (int) $store->id)]);

            $manager = $this->resolveManager($data);
            $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('is_active', true)->firstOrFail();

            DB::table('user_store_roles')->updateOrInsert(
                ['user_id' => $manager->id, 'store_id' => $store->id, 'role_id' => $role->id],
                ['created_at' => now(), 'updated_at' => now()],
            );

            $wholesaleCustomer = $this->wholesaleAccounts->ensureForStore($store, (int) $data['price_tier_id'], $manager);

            $this->audit->record('retail_store.provisioned', $actor, $store, null, [
                'store_id' => $store->id,
                'b2b_customer_id' => $wholesaleCustomer->getKey(),
                'manager_user_id' => $manager->id,
                'manager_role' => $role->code,
                'price_tier_id' => (int) $data['price_tier_id'],
                'default_customer_wholesale_price_tier_id' => $store->default_customer_wholesale_price_tier_id,
                'logo_path' => $store->logo_path,
                'is_active' => $store->is_active,
                'advertising_enabled' => $store->advertising_enabled,
                'live_ads_enabled' => $store->live_ads_enabled,
                'coupons_enabled' => $store->coupons_enabled,
            ], $request);

            return $store;
        });

        return redirect()->route('admin.retail-stores.index')
            ->with('status', $this->msg('تم إنشاء متجر التجزئة وتعيين مديره.', 'Retail store provisioned and manager assigned.'));
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('stores', 'code')->ignore($store->id)],
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'price_tier_id' => ['required', 'integer', 'exists:b2b_price_tiers,id'],
            'default_customer_wholesale_price_tier_id' => ['nullable', 'integer', 'exists:b2b_price_tiers,id'],
            'is_active' => ['nullable', 'boolean'],
            'advertising_enabled' => ['nullable', 'boolean'],
            'live_ads_enabled' => ['nullable', 'boolean'],
            'coupons_enabled' => ['nullable', 'boolean'],
            'primary_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $primaryOwner = null;
        $primaryOwnerId = $data['primary_owner_user_id'] ?? null;
        if (is_numeric($primaryOwnerId) && (int) $primaryOwnerId > 0) {
            $primaryOwner = User::query()
                ->whereKey((int) $primaryOwnerId)
                ->where('is_active', true)
                ->first();

            abort_unless($primaryOwner instanceof User, 422, 'Primary owner must be an active user.');
        }

        $before = $store->only([
            'code',
            'name',
            'logo_path',
            'default_customer_wholesale_price_tier_id',
            'is_active',
            'advertising_enabled',
            'live_ads_enabled',
            'coupons_enabled',
        ]);

        $oldLogo = $store->logo_path;
        $updates = [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'default_customer_wholesale_price_tier_id' => $data['default_customer_wholesale_price_tier_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'advertising_enabled' => $request->boolean('advertising_enabled'),
            'live_ads_enabled' => $request->boolean('live_ads_enabled'),
            'coupons_enabled' => $request->boolean('coupons_enabled'),
        ];
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo');
            abort_unless($logo instanceof UploadedFile, 422, 'Invalid store logo upload.');
            $updates['logo_path'] = $this->logos->store($logo, (int) $store->id);
        }
        $store->update($updates);
        if (array_key_exists('logo_path', $updates)) {
            $this->logos->delete($oldLogo);
        }
        $ownerChange = null;
        if ($primaryOwner instanceof User) {
            $ownerChange = $this->bindPrimaryOwner(
                $store,
                $primaryOwner,
                (int) $data['price_tier_id'],
            );
        } else {
            $this->wholesaleAccounts->syncForStore($store, (int) $data['price_tier_id']);
        }

        $after = $store->only([
            'code',
            'name',
            'logo_path',
            'default_customer_wholesale_price_tier_id',
            'is_active',
            'advertising_enabled',
            'live_ads_enabled',
            'coupons_enabled',
        ]);
        $after['price_tier_id'] = (int) $data['price_tier_id'];
        $this->audit->record('retail_store.updated', $actor, $store, $before, $after, $request);

        if (
            is_array($ownerChange)
            && ($ownerChange['before']['owner_user_id'] ?? null)
                !== $ownerChange['after']['owner_user_id']
        ) {
            $this->audit->record(
                'retail_store.owner_reassigned',
                $actor,
                $store,
                $ownerChange['before'],
                $ownerChange['after'],
                $request,
            );
        }

        return back()->with('status', $this->msg('تم تحديث المتجر.', 'Retail store updated.'));
    }

    public function assignRole(Request $request, Store $store): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $role = Role::query()->whereKey($data['role_id'])->where('is_active', true)->where('scope', 'store')->firstOrFail();
        $managedUser = User::query()->whereKey($data['user_id'])->where('is_active', true)->firstOrFail();

        DB::table('user_store_roles')->updateOrInsert(
            ['user_id' => $managedUser->id, 'store_id' => $store->id, 'role_id' => $role->id],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $this->audit->record('retail_store.role_assigned', $actor, $store, null, [
            'user_id' => $managedUser->id,
            'role_id' => $role->id,
            'role_code' => $role->code,
        ], $request);

        return back()->with('status', $this->msg('تم إسناد الدور للمتجر.', 'Store role assigned.'));
    }

    public function removeRole(Request $request, Store $store, int $assignment): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);

        $result = DB::transaction(function () use ($store, $assignment): array {
            $row = DB::table('user_store_roles')
                ->where('id', $assignment)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();
            abort_unless($row !== null, 404);

            $roleCode = DB::table('roles')->where('id', $row->role_id)->value('code');
            $link = DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $store->id)
                ->lockForUpdate()
                ->first();

            $ownerRevoked = $roleCode === 'B2C_STORE_ADMIN'
                && $link !== null
                && $link->owner_user_id !== null
                && (int) $link->owner_user_id === (int) $row->user_id;

            DB::table('user_store_roles')->where('id', $assignment)->delete();

            if ($ownerRevoked) {
                DB::table('retail_wholesale_accounts')
                    ->where('retail_store_id', $store->id)
                    ->update([
                        'owner_user_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            return [
                'row' => $row,
                'role_code' => $roleCode,
                'owner_revoked' => $ownerRevoked,
                'b2b_customer_id' => $link?->b2b_customer_id === null
                    ? null
                    : (int) $link->b2b_customer_id,
            ];
        }, 3);

        $row = $result['row'];
        $this->audit->record('retail_store.role_removed', $actor, $store, [
            'assignment_id' => $assignment,
            'user_id' => $row->user_id,
            'role_id' => $row->role_id,
        ], null, $request);

        if ($result['owner_revoked']) {
            $this->audit->record(
                'retail_store.owner_revoked',
                $actor,
                $store,
                [
                    'store_id' => (int) $store->id,
                    'owner_user_id' => (int) $row->user_id,
                    'b2b_customer_id' => $result['b2b_customer_id'],
                ],
                [
                    'store_id' => (int) $store->id,
                    'owner_user_id' => null,
                    'b2b_customer_id' => $result['b2b_customer_id'],
                ],
                $request,
            );
        }

        return back()->with('status', $this->msg('تم إلغاء إسناد الدور.', 'Store role assignment removed.'));
    }

    public function inspect(Request $request, Store $store): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);
        $this->tenantContext->retail($actor, (int) $store->id, true, $request);

        return redirect()->route('admin.b2c.dashboard', [
            'store_id' => $store->id,
            'support_access' => 1,
        ]);
    }

    /**
     * @return array{
     *   before:array{store_id:int,owner_user_id:?int,b2b_customer_id:?int},
     *   after:array{store_id:int,owner_user_id:int,b2b_customer_id:int,price_tier_id:?int}
     * }
     */
    private function bindPrimaryOwner(Store $store, User $newOwner, int $priceTierId): array
    {
        return DB::transaction(function () use ($store, $newOwner, $priceTierId): array {
            $managerRole = Role::query()
                ->where('code', 'B2C_STORE_ADMIN')
                ->where('is_active', true)
                ->firstOrFail();

            $linkBefore = DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $store->id)
                ->lockForUpdate()
                ->first();

            $oldOwnerId = $linkBefore?->owner_user_id === null
                ? null
                : (int) $linkBefore->owner_user_id;

            if ($oldOwnerId !== null && $oldOwnerId !== (int) $newOwner->id) {
                DB::table('user_store_roles')
                    ->where('user_id', $oldOwnerId)
                    ->where('store_id', $store->id)
                    ->where('role_id', $managerRole->id)
                    ->delete();
            }

            DB::table('user_store_roles')->updateOrInsert(
                [
                    'user_id' => $newOwner->id,
                    'store_id' => $store->id,
                    'role_id' => $managerRole->id,
                ],
                ['created_at' => now(), 'updated_at' => now()],
            );

            $customer = $this->wholesaleAccounts->ensureForStore(
                $store,
                $priceTierId,
                $newOwner,
            );

            $linkAfter = DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $store->id)
                ->first();
            abort_unless(
                $linkAfter !== null,
                500,
                'Retail Wholesale owner link was not persisted.',
            );

            $effectiveTierId = DB::table('b2b_accounts')
                ->where('b2b_customer_id', $customer->getKey())
                ->value('price_tier_id');

            return [
                'before' => [
                    'store_id' => (int) $store->id,
                    'owner_user_id' => $oldOwnerId,
                    'b2b_customer_id' => $linkBefore?->b2b_customer_id === null
                        ? null
                        : (int) $linkBefore->b2b_customer_id,
                ],
                'after' => [
                    'store_id' => (int) $store->id,
                    'owner_user_id' => (int) $newOwner->id,
                    'b2b_customer_id' => (int) $linkAfter->b2b_customer_id,
                    'price_tier_id' => $effectiveTierId === null ? null : (int) $effectiveTierId,
                ],
            ];
        }, 3);
    }

    private function resolveManager(array $data): User
    {
        if ($data['manager_mode'] === 'existing') {
            return User::query()
                ->whereKey($data['manager_user_id'] ?? 0)
                ->where('is_active', true)
                ->firstOrFail();
        }

        validator($data, [
            'manager_name' => ['required', 'string', 'max:255'],
            'manager_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'manager_password' => ['required', 'string', 'min:8', 'max:255'],
        ])->validate();

        return User::query()->create([
            'name' => $data['manager_name'],
            'email' => $data['manager_email'],
            'password' => Hash::make($data['manager_password']),
            'locale' => 'ar',
            'is_active' => true,
        ]);
    }

    private function superAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        return $actor;
    }

    private function assertRetailStore(Store $store): void
    {
        $isRetail = DB::table('store_types')
            ->where('id', $store->store_type_id)
            ->where('code', 'B2C')
            ->exists();
        abort_unless($isRetail, 404);
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
