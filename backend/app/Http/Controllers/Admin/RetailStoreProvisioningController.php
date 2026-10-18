<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreFeatureService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RetailWholesaleAccountService;
use App\Services\StoreChannelOperationsService;
use App\Services\StoreLogoService;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class RetailStoreProvisioningController extends Controller
{
    private const PROFILE_RETAIL = 'RETAIL_STORE';

    private const PROFILE_RESTAURANT = 'RESTAURANT';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContextResolver $tenantContext,
        private readonly RetailWholesaleAccountService $wholesaleAccounts,
        private readonly StoreLogoService $logos,
        private readonly StoreChannelOperationsService $storeChannels,
        private readonly RestaurantSaasStoreFeatureService $restaurantSaas,
    ) {}

    public function index(Request $request): View|StreamedResponse
    {
        $actor = $this->superAdmin($request);
        $search = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        if ($request->filled('export')) {
            $report = (string) $request->query('export');
            $rows = $this->storeChannels->reportRows($report, is_string($from) ? $from : null, is_string($to) ? $to : null);

            return response()->streamDownload(function () use ($rows): void {
                $stream = fopen('php://output', 'wb');
                if ($stream === false) {
                    return;
                }
                if ($rows !== []) {
                    fputcsv($stream, array_keys($rows[0]));
                    foreach ($rows as $row) {
                        fputcsv($stream, array_map(static fn ($value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row));
                    }
                }
                fclose($stream);
            }, 'stores-channels-'.$report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $storeChannelDashboard = $this->storeChannels->dashboard(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

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
        $restaurantSaasEnabledStoreIds = $this->restaurantSaas->enabledStoreIds($storeIds);
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

        $stores->getCollection()->transform(function (Store $store) use ($commerceByStore, $priceTierById, $restaurantSaasEnabledStoreIds): Store {
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
            $restaurantSaasEnabled = in_array((int) $store->id, $restaurantSaasEnabledStoreIds, true);
            $store->setAttribute('restaurant_saas_enabled', $restaurantSaasEnabled);

            $persistedBusinessProfile = strtoupper(trim((string) $store->getAttribute('business_profile')));
            $store->setAttribute(
                'business_profile',
                in_array($persistedBusinessProfile, [self::PROFILE_RETAIL, self::PROFILE_RESTAURANT], true)
                    ? $persistedBusinessProfile
                    : ($restaurantSaasEnabled ? self::PROFILE_RESTAURANT : self::PROFILE_RETAIL),
            );

            $customerTierId = $store->default_customer_wholesale_price_tier_id;
            $customerTier = $customerTierId === null ? null : $priceTierById->get((int) $customerTierId);
            $store->setAttribute('customer_wholesale_price_tier_name', $customerTier?->name);

            return $store;
        });
        $stores->setCollection($this->storeChannels->enrichStores($stores->getCollection()));

        return view('admin.retail-stores', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'retail_store_provisioning',
            'stores' => $stores,
            'search' => $search,
            'storeRoles' => Role::query()->where('is_active', true)->where('scope', 'store')->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'priceTiers' => $priceTiers,
            'storeChannelDashboard' => $storeChannelDashboard,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $data = $request->validate([
            'business_profile' => ['required', Rule::in([self::PROFILE_RETAIL, self::PROFILE_RESTAURANT])],
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

        $businessProfile = strtoupper((string) $data['business_profile']);
        if ($businessProfile === self::PROFILE_RESTAURANT && ! $request->boolean('is_active', true)) {
            throw ValidationException::withMessages([
                'is_active' => [$this->msg(
                    'يجب إنشاء المطعم بحالة نشطة حتى تكتمل تهيئة مساحة العمل.',
                    'Restaurants must be created active so their workspace can be provisioned.',
                )],
            ]);
        }

        $storedLogoPath = null;

        try {
            DB::transaction(function () use ($request, $data, $actor, $businessProfile, &$storedLogoPath): void {
                $storeTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
                abort_if($storeTypeId === null, 500, 'B2C store type is not configured.');

                $attributes = [
                    'store_type_id' => $storeTypeId,
                    'default_customer_wholesale_price_tier_id' => $businessProfile === self::PROFILE_RETAIL
                        ? ($data['default_customer_wholesale_price_tier_id'] ?? null)
                        : null,
                    'code' => strtoupper($data['code']),
                    'name' => $data['name'],
                    'logo_path' => null,
                    'is_active' => $request->boolean('is_active', true),
                    'advertising_enabled' => $request->boolean('advertising_enabled', true),
                    'live_ads_enabled' => $request->boolean('live_ads_enabled', true),
                    'coupons_enabled' => $request->boolean('coupons_enabled', true),
                ];

                // #2168 owns the authoritative column/migration. Persist it as soon as that
                // contract exists, while keeping this provisioning child independently mergeable.
                if (Schema::hasColumn('stores', 'business_profile')) {
                    $attributes['business_profile'] = $businessProfile;
                }

                $store = Store::query()->create($attributes);
                $logo = $request->file('logo');
                abort_unless($logo instanceof UploadedFile, 422, 'Store logo is required.');
                $storedLogoPath = $this->logos->store($logo, (int) $store->id);
                $store->update(['logo_path' => $storedLogoPath]);

                $manager = $this->resolveManager($data);

                // The wholesale buyer identity is shared infrastructure for both B2C profiles.
                // Restaurant provisioning reuses it rather than creating a parallel buyer model.
                $wholesaleCustomer = $this->wholesaleAccounts->ensureForStore(
                    $store,
                    (int) $data['price_tier_id'],
                    $manager,
                );

                $restaurantBranchId = null;
                if ($businessProfile === self::PROFILE_RESTAURANT) {
                    $restaurantBranchId = $this->provisionRestaurantBaseline($store, $actor, $request);
                }

                // Keep the existing manager grant contract until the profile-aware RBAC child
                // (#2170) replaces the role selection authority.
                $role = Role::query()
                    ->where('code', 'B2C_STORE_ADMIN')
                    ->where('is_active', true)
                    ->firstOrFail();

                DB::table('user_store_roles')->updateOrInsert(
                    ['user_id' => $manager->id, 'store_id' => $store->id, 'role_id' => $role->id],
                    ['created_at' => now(), 'updated_at' => now()],
                );

                $this->audit->record('retail_store.provisioned', $actor, $store, null, [
                    'store_id' => $store->id,
                    'business_profile' => $businessProfile,
                    'b2b_customer_id' => $wholesaleCustomer->getKey(),
                    'manager_user_id' => $manager->id,
                    'manager_role' => $role->code,
                    'price_tier_id' => (int) $data['price_tier_id'],
                    'default_customer_wholesale_price_tier_id' => $store->default_customer_wholesale_price_tier_id,
                    'restaurant_saas_enabled' => $businessProfile === self::PROFILE_RESTAURANT,
                    'restaurant_branch_id' => $restaurantBranchId,
                    'logo_path' => $store->logo_path,
                    'is_active' => $store->is_active,
                    'advertising_enabled' => $store->advertising_enabled,
                    'live_ads_enabled' => $store->live_ads_enabled,
                    'coupons_enabled' => $store->coupons_enabled,
                ], $request);
            }, 3);
        } catch (Throwable $e) {
            if (is_string($storedLogoPath) && $storedLogoPath !== '') {
                $this->logos->delete($storedLogoPath);
            }

            throw $e;
        }

        return redirect()->route('admin.retail-stores.index')
            ->with(
                'status',
                $businessProfile === self::PROFILE_RESTAURANT
                    ? $this->msg(
                        'تم إنشاء المطعم وتهيئة مساحة المطعم وحساب الشراء من الجملة.',
                        'Restaurant provisioned with its restaurant workspace and Wholesale purchasing identity.',
                    )
                    : $this->msg(
                        'تم إنشاء متجر التجزئة وتعيين مديره بدون تفعيل وحدات المطعم.',
                        'Retail store provisioned and manager assigned without Restaurant modules.',
                    ),
            );
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);

        if ($request->filled('lifecycle_action')) {
            $lifecycle = $request->validate([
                'lifecycle_action' => ['required', Rule::in(['activate', 'suspend', 'close'])],
                'lifecycle_reason' => ['required', 'string', 'min:5', 'max:500'],
            ]);
            $this->storeChannels->transition(
                $actor,
                $store,
                (string) $lifecycle['lifecycle_action'],
                (string) $lifecycle['lifecycle_reason'],
                $request,
            );

            return back()->with('status', $this->msg('تم تحديث دورة حياة المتجر مع تسجيل السبب.', 'Store lifecycle updated with an audited reason.'));
        }

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

    public function restaurantSaas(Request $request, Store $store): RedirectResponse
    {
        $actor = $this->superAdmin($request);
        $this->assertRetailStore($store);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $this->restaurantSaas->setEnabled($store, $enabled, $actor, $request);

        return back()->with(
            'status',
            __($enabled
                ? 'smart_ordering.retail_feature.saved_enabled'
                : 'smart_ordering.retail_feature.saved_disabled'),
        );
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

    private function provisionRestaurantBaseline(Store $store, User $actor, Request $request): int
    {
        $this->restaurantSaas->setEnabled($store, true, $actor, $request);

        $siteId = DB::table('smart_ordering_sites')
            ->where('store_id', $store->getKey())
            ->value('id');
        abort_if($siteId === null, 500, 'Restaurant site provisioning did not create a site.');

        $existingBranchId = DB::table('smart_ordering_branches')
            ->where('site_id', $siteId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        if ($existingBranchId !== null) {
            return (int) $existingBranchId;
        }

        return (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => (int) $siteId,
            'code' => 'MAIN',
            'name_ar' => (string) $store->name,
            'name_en' => (string) $store->name,
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => false,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
