<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogImageService;
use App\Services\LookupScopeService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LookupManagementController extends Controller
{
    public function __construct(
        private readonly LookupScopeService $scope,
        private readonly AuditLogger $audit,
        private readonly CatalogImageService $images,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor);

        $type = in_array((string) $request->query('type'), ['brands', 'units'], true)
            ? (string) $request->query('type')
            : 'brands';
        $table = $type;
        $stores = $this->scope->visibleRetailStores($actor);
        $isSuperAdmin = $actor->hasRole('SUPER_ADMIN');
        $isB2bAdmin = $isSuperAdmin === false && $this->scope->canAccessWholesale($actor);
        $retailStoreIds = $stores->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $isRetailScoped = $isSuperAdmin === false && $isB2bAdmin === false && $retailStoreIds !== [];
        $currentStoreId = null;

        if ($isRetailScoped) {
            $requestedStoreId = $request->integer('store_id');
            if ($requestedStoreId > 0) {
                abort_unless(collect($retailStoreIds)->contains($requestedStoreId), 404);
                $currentStoreId = $requestedStoreId;
            } else {
                $currentStoreId = $retailStoreIds[0];
            }
        }

        if ($type === 'brands') {
            $query = $this->scope->visible(Brand::query(), $actor, 'brands');
        } else {
            $query = $this->scope->visible(Unit::query(), $actor, 'units');
        }

        $query->leftJoin('stores', 'stores.id', '=', $table.'.store_id');

        if ($isRetailScoped) {
            $query->where($table.'.scope', LookupScopeService::STORE)
                ->where($table.'.store_id', $currentStoreId);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($filter) use ($table, $type, $term): void {
                $filter
                    ->where($table.'.name', 'like', $term)
                    ->orWhere($table.'.name_ar', 'like', $term)
                    ->orWhere($table.'.name_en', 'like', $term)
                    ->orWhere($table.'.'.($type === 'brands' ? 'slug' : 'code'), 'like', $term);
            });
        }

        $scope = $isSuperAdmin ? (string) $request->query('scope', 'all') : 'all';
        if ($isSuperAdmin && in_array($scope, [LookupScopeService::GLOBAL, LookupScopeService::B2B, LookupScopeService::STORE], true)) {
            $query->where($table.'.scope', $scope);
        }

        $status = (string) $request->query('status', 'all');
        if ($status === 'active') {
            $query->where($table.'.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where($table.'.is_active', false);
        }

        $storeId = $isSuperAdmin ? $request->integer('store_id') : ($currentStoreId ?? 0);
        if ($isSuperAdmin && $storeId > 0) {
            $query->where($table.'.store_id', $storeId);
        }

        $records = $query
            ->select([$table.'.*', 'stores.name as store_name'])
            ->orderBy($table.'.name')
            ->paginate(50)
            ->withQueryString();

        foreach ($records->items() as $record) {
            $record->setAttribute(
                'can_manage',
                $this->scope->canManage(
                    $actor,
                    (string) $record->scope,
                    $record->store_id === null ? null : (int) $record->store_id,
                ),
            );
        }

        return view('admin.lookup-management', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'lookup_management',
            'type' => $type,
            'records' => $records,
            'stores' => $stores,
            'manageableScopes' => $this->scope->manageableScopes($actor),
            'isSuperAdmin' => $isSuperAdmin,
            'isB2bAdmin' => $isB2bAdmin,
            'isRetailScoped' => $isRetailScoped,
            'currentStoreId' => $currentStoreId,
            'filters' => [
                'q' => $search,
                'scope' => $scope,
                'status' => $status,
                'store_id' => $storeId > 0 ? $storeId : null,
            ],
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->assertType($type);
        $actor = $this->actor($request);
        $payload = $this->payload($request, $type, true, $actor);
        $this->requireExplicitSupportAccess($actor, $payload['scope'], $request);
        $this->authorizeScopedPermission($actor, 'lookups.manage', $payload['scope'], $payload['store_id']);

        $scopeKey = $this->scope->authorizeMutation(
            $actor,
            $payload['scope'],
            $payload['store_id'],
            $request->boolean('support_access'),
            $request,
        );
        $payload['scope_key'] = $scopeKey;
        $this->assertUnique($type, $payload, null);

        $lookup = $type === 'brands'
            ? Brand::query()->create($payload)
            : Unit::query()->create($payload);

        if ($type === 'brands') {
            $brandImage = $request->file('brand_image');
            if ($brandImage instanceof UploadedFile) {
                $this->images->replaceBrandImage((int) $lookup->getKey(), $brandImage);
                $lookup->refresh();
            }
        }

        $this->audit->record(
            'lookup.'.$this->singular($type).'.created',
            $actor,
            $lookup,
            null,
            $lookup->toArray(),
            $request,
        );

        return $this->redirect($type, $this->msg('تمت إضافة القيمة المرجعية.', 'Lookup value added.'), $payload['store_id']);
    }

    public function update(Request $request, string $type, int $lookup): RedirectResponse
    {
        $this->assertType($type);
        $actor = $this->actor($request);
        $model = $this->find($type, $lookup);
        $this->requireExplicitSupportAccess($actor, (string) $model->scope, $request);
        $this->authorizeScopedPermission(
            $actor,
            'lookups.manage',
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
        );

        $this->scope->authorizeMutation(
            $actor,
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
        );

        $payload = $this->payload($request, $type, false, $actor, $model);
        $this->requireExplicitSupportAccess($actor, $payload['scope'], $request);
        $this->authorizeScopedPermission($actor, 'lookups.manage', $payload['scope'], $payload['store_id']);
        $payload['scope_key'] = $this->scope->authorizeMutation(
            $actor,
            $payload['scope'],
            $payload['store_id'],
            $request->boolean('support_access'),
            $request,
        );
        $this->assertUnique($type, $payload, (int) $model->getKey());

        $before = $model->toArray();
        $model->fill($payload)->save();

        if ($type === 'brands') {
            $brandImage = $request->file('brand_image');
            if ($brandImage instanceof UploadedFile) {
                $this->images->replaceBrandImage((int) $model->getKey(), $brandImage);
                $model->refresh();
            }
        }

        $this->audit->record(
            'lookup.'.$this->singular($type).'.updated',
            $actor,
            $model,
            $before,
            $model->fresh()?->toArray(),
            $request,
        );

        return $this->redirect($type, $this->msg('تم تحديث القيمة المرجعية.', 'Lookup value updated.'), $payload['store_id']);
    }

    public function toggle(Request $request, string $type, int $lookup): RedirectResponse
    {
        $this->assertType($type);
        $actor = $this->actor($request);
        $model = $this->find($type, $lookup);
        $this->requireExplicitSupportAccess($actor, (string) $model->scope, $request);
        $this->authorizeScopedPermission(
            $actor,
            'lookups.manage',
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
        );

        $this->scope->authorizeMutation(
            $actor,
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
        );

        $before = $model->toArray();
        $model->is_active = (bool) $model->is_active === false;
        $model->save();

        $this->audit->record(
            'lookup.'.$this->singular($type).'.status_changed',
            $actor,
            $model,
            $before,
            $model->fresh()?->toArray(),
            $request,
        );

        return $this->redirect(
            $type,
            $this->msg('تم تحديث حالة القيمة المرجعية.', 'Lookup status updated.'),
            $model->store_id === null ? null : (int) $model->store_id,
        );
    }

    public function destroy(Request $request, string $type, int $lookup): RedirectResponse
    {
        $this->assertType($type);
        $actor = $this->actor($request);
        $model = $this->find($type, $lookup);
        $this->requireExplicitSupportAccess($actor, (string) $model->scope, $request);
        $this->authorizeScopedPermission(
            $actor,
            'lookups.manage',
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
        );

        $this->scope->authorizeMutation(
            $actor,
            (string) $model->scope,
            $model->store_id === null ? null : (int) $model->store_id,
            $request->boolean('support_access'),
            $request,
        );

        $referenceColumn = $type === 'brands' ? 'brand_id' : 'unit_id';
        if (DB::table('products')->where($referenceColumn, $model->getKey())->exists()) {
            return back()->withErrors([
                'lookup' => $this->msg(
                    'لا يمكن حذف قيمة مستخدمة بواسطة منتجات. عطّلها بدلاً من الحذف.',
                    'A lookup referenced by products cannot be deleted. Deactivate it instead.',
                ),
            ]);
        }

        $before = $model->toArray();
        if ($type === 'brands') {
            $this->images->removeBrandImage((int) $model->getKey());
        }
        $this->audit->record(
            'lookup.'.$this->singular($type).'.deleted',
            $actor,
            $model,
            $before,
            null,
            $request,
        );
        $model->delete();

        return $this->redirect(
            $type,
            $this->msg('تم حذف القيمة المرجعية.', 'Lookup value deleted.'),
            $model->store_id === null ? null : (int) $model->store_id,
        );
    }

    /** @return array<string,mixed> */
    private function payload(
        Request $request,
        string $type,
        bool $creating,
        User $actor,
        Brand|Unit|null $existing = null,
    ): array {
        $rules = [
            'scope' => ['nullable', Rule::in([
                LookupScopeService::GLOBAL,
                LookupScopeService::B2B,
                LookupScopeService::STORE,
            ])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'support_access' => ['nullable', 'boolean'],
        ];

        if ($type === 'brands') {
            $rules['slug'] = ['nullable', 'string', 'max:255'];
            $rules['brand_image'] = [
                ($creating || ($existing instanceof Brand && blank($existing->image_path))) ? 'required' : 'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=256,min_height=256,max_width=2048,max_height=2048',
            ];
        } else {
            $rules['code'] = ['required', 'string', 'max:50'];
            $rules['decimal_places'] = ['required', 'integer', 'between:0,6'];
        }

        $data = $request->validate($rules);
        [$scope, $storeId] = $this->mutationScope($actor, $data);

        $payload = [
            'scope' => $scope,
            'store_id' => $storeId,
            'name' => (string) $data['name_en'],
            'name_ar' => (string) $data['name_ar'],
            'name_en' => (string) $data['name_en'],
            'is_active' => $creating ? $request->boolean('is_active', true) : $request->boolean('is_active'),
        ];

        if ($type === 'brands') {
            $slug = trim((string) ($data['slug'] ?? ''));
            if ($slug === '') {
                $slug = Str::slug((string) $data['name_en']);
            }
            if ($slug === '') {
                $slug = 'brand-'.Str::lower(Str::random(8));
            }
            $payload['slug'] = Str::lower($slug);
        } else {
            $payload['code'] = Str::upper(trim((string) $data['code']));
            $payload['decimal_places'] = (int) $data['decimal_places'];
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function assertUnique(string $type, array $payload, ?int $ignoreId): void
    {
        $column = $type === 'brands' ? 'slug' : 'code';
        $query = $type === 'brands' ? Brand::query() : Unit::query();
        $query
            ->where('scope_key', $payload['scope_key'])
            ->where($column, $payload[$column]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                $column => [$this->msg(
                    'هذه القيمة موجودة بالفعل داخل نفس النطاق.',
                    'This value already exists inside the same scope.',
                )],
            ]);
        }
    }

    private function authorizeView(User $actor): void
    {
        if (Gate::forUser($actor)->allows('lookups.view')) {
            return;
        }

        foreach ($this->scope->visibleRetailStores($actor) as $store) {
            if (Gate::forUser($actor)->allows('lookups.view', (int) $store->id)) {
                return;
            }
        }

        abort(403);
    }

    private function authorizeScopedPermission(
        User $actor,
        string $permission,
        string $scope,
        ?int $storeId,
    ): void {
        if ($scope === LookupScopeService::STORE) {
            abort_if($storeId === null, 422);
            Gate::forUser($actor)->authorize($permission, $storeId);

            return;
        }

        Gate::forUser($actor)->authorize($permission);
    }

    private function find(string $type, int $id): Brand|Unit
    {
        return $type === 'brands'
            ? Brand::query()->findOrFail($id)
            : Unit::query()->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertType(string $type): void
    {
        abort_unless(in_array($type, ['brands', 'units'], true), 404);
    }

    private function singular(string $type): string
    {
        return $type === 'brands' ? 'brand' : 'unit';
    }

    /** @param array<string,mixed> $data
     * @return array{0:string,1:?int}
     */
    private function mutationScope(User $actor, array $data): array
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            $scope = (string) ($data['scope'] ?? '');
            if (in_array($scope, [LookupScopeService::GLOBAL, LookupScopeService::B2B, LookupScopeService::STORE], true) === false) {
                throw ValidationException::withMessages([
                    'scope' => [$this->msg('اختر نطاقًا صالحًا.', 'Select a valid scope.')],
                ]);
            }

            $storeId = $scope === LookupScopeService::STORE && isset($data['store_id'])
                ? (int) $data['store_id']
                : null;

            if ($scope === LookupScopeService::STORE && $storeId === null) {
                throw ValidationException::withMessages([
                    'store_id' => [$this->msg('المتجر مطلوب لنطاق التجزئة.', 'A store is required for Retail Store scope.')],
                ]);
            }

            return [$scope, $storeId];
        }

        if ($this->scope->canAccessWholesale($actor)) {
            if (isset($data['scope']) && $data['scope'] !== LookupScopeService::B2B) {
                abort(403);
            }
            if (empty($data['store_id']) === false) {
                abort(403);
            }

            return [LookupScopeService::B2B, null];
        }

        $storeIds = $this->scope->visibleRetailStores($actor)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        abort_if($storeIds === [], 403);

        if (isset($data['scope']) && $data['scope'] !== LookupScopeService::STORE) {
            abort(403);
        }

        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : ($storeIds[0] ?? 0);
        abort_unless(collect($storeIds)->contains($storeId), 404);

        return [LookupScopeService::STORE, $storeId];
    }

    private function requireExplicitSupportAccess(User $actor, string $scope, Request $request): void
    {
        if (! $actor->hasRole('SUPER_ADMIN')
            || $scope !== LookupScopeService::STORE
            || $request->boolean('support_access')) {
            return;
        }

        throw ValidationException::withMessages([
            'support_access' => [$this->msg(
                'فعّل «الدعم الصريح» لإدارة بيانات متجر التجزئة بصلاحية مالك المنصة.',
                'Enable explicit support access to manage Retail-store data as the platform owner.',
            )],
        ]);
    }

    private function redirect(string $type, string $message, ?int $storeId = null): RedirectResponse
    {
        $params = ['type' => $type];
        if ($storeId !== null) {
            $params['store_id'] = $storeId;
        }

        return redirect()->route('admin.lookups.index', $params)->with('status', $message);
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
