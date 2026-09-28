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
            ->orderBy('stores.name')
            ->paginate(25)
            ->withQueryString();

        $storeIds = $stores->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $priceTierByStore = DB::table('retail_wholesale_accounts')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
            ->whereIn('retail_wholesale_accounts.retail_store_id', $storeIds)
            ->get([
                'retail_wholesale_accounts.retail_store_id',
                'b2b_accounts.price_tier_id',
                'b2b_price_tiers.name as price_tier_name',
            ])
            ->keyBy('retail_store_id');

        $stores->getCollection()->transform(function (Store $store) use ($priceTierByStore): Store {
            $tier = $priceTierByStore->get($store->id);
            $store->setAttribute('wholesale_price_tier_id', $tier?->price_tier_id === null ? null : (int) $tier->price_tier_id);
            $store->setAttribute('wholesale_price_tier_name', $tier?->price_tier_name);

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
            'priceTiers' => DB::table('b2b_price_tiers')->orderBy('priority')->orderBy('name')->get(['id', 'code', 'name']),
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
            'is_active' => ['nullable', 'boolean'],
            'manager_mode' => ['required', Rule::in(['existing', 'new'])],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_email' => ['nullable', 'email', 'max:255'],
            'manager_password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $store = DB::transaction(function () use ($request, $data, $actor): Store {
            $storeTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
            abort_if($storeTypeId === null, 500, 'Retail store type is not configured.');

            $store = Store::query()->create([
                'store_type_id' => $storeTypeId,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'logo_path' => null,
                'is_active' => $request->boolean('is_active', true),
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

            $wholesaleCustomer = $this->wholesaleAccounts->ensureForStore($store, (int) $data['price_tier_id']);

            $this->audit->record('retail_store.provisioned', $actor, $store, null, [
                'store_id' => $store->id,
                'b2b_customer_id' => $wholesaleCustomer->getKey(),
                'manager_user_id' => $manager->id,
                'manager_role' => $role->code,
                'price_tier_id' => (int) $data['price_tier_id'],
                'logo_path' => $store->logo_path,
                'is_active' => $store->is_active,
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
            'is_active' => ['nullable', 'boolean'],
        ]);
        $before = $store->only(['code', 'name', 'logo_path', 'is_active']);

        $oldLogo = $store->logo_path;
        $updates = [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active'),
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
        $this->wholesaleAccounts->syncForStore($store, (int) $data['price_tier_id']);

        $after = $store->only(['code', 'name', 'logo_path', 'is_active']);
        $after['price_tier_id'] = (int) $data['price_tier_id'];
        $this->audit->record('retail_store.updated', $actor, $store, $before, $after, $request);

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

        $row = DB::table('user_store_roles')->where('id', $assignment)->where('store_id', $store->id)->first();
        abort_unless($row !== null, 404);

        DB::table('user_store_roles')->where('id', $assignment)->delete();

        $this->audit->record('retail_store.role_removed', $actor, $store, [
            'assignment_id' => $assignment,
            'user_id' => $row->user_id,
            'role_id' => $row->role_id,
        ], null, $request);

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
