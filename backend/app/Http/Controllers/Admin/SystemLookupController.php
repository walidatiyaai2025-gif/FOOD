<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bPriceTier;
use App\Models\OperationalLookup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalLookupService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SystemLookupController extends Controller
{
    /** @var array<string,array{type:string,title_ar:string,title_en:string}> */
    private const TYPES = [
        'payment-operation-types' => [
            'type' => OperationalLookupService::PAYMENT_OPERATION_TYPE,
            'title_ar' => 'أنواع عمليات الدفع',
            'title_en' => 'Payment operation types',
        ],
        'payment-methods' => [
            'type' => OperationalLookupService::PAYMENT_METHOD,
            'title_ar' => 'طرق الدفع',
            'title_en' => 'Payment methods',
        ],
        'pricing-tiers' => [
            'type' => OperationalLookupService::PRICE_TIER,
            'title_ar' => 'شرائح التسعير',
            'title_en' => 'Pricing tiers',
        ],
        'order-statuses' => [
            'type' => OperationalLookupService::ORDER_STATUS,
            'title_ar' => 'حالات الطلب',
            'title_en' => 'Order statuses',
        ],
        'failed-delivery-reasons' => [
            'type' => OperationalLookupService::FAILED_DELIVERY_REASON,
            'title_ar' => 'أسباب تعذر التوصيل',
            'title_en' => 'Failed-delivery reasons',
        ],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor);

        $key = $this->typeKey((string) $request->query('type', 'payment-operation-types'));
        $definition = self::TYPES[$key];
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('q', ''));

        if ($definition['type'] === OperationalLookupService::PRICE_TIER) {
            $query = B2bPriceTier::query()
                ->when($status === 'active', fn ($query) => $query->where('is_active', true))
                ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
                ->when($search !== '', fn ($query) => $query->where(function ($filter) use ($search): void {
                    $term = '%'.$search.'%';
                    $filter->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('name_ar', 'like', $term)
                        ->orWhere('name_en', 'like', $term);
                }))
                ->orderBy('priority')
                ->orderBy('id');

            $records = $query->paginate(100)->withQueryString();
        } else {
            $query = OperationalLookup::query()
                ->where('type', $definition['type'])
                ->when($status === 'active', fn ($query) => $query->where('is_active', true))
                ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
                ->when($search !== '', fn ($query) => $query->where(function ($filter) use ($search): void {
                    $term = '%'.$search.'%';
                    $filter->where('code', 'like', $term)
                        ->orWhere('label_ar', 'like', $term)
                        ->orWhere('label_en', 'like', $term);
                }))
                ->orderBy('sort_order')
                ->orderBy('id');

            $records = $query->paginate(100)->withQueryString();
        }

        return view('admin.system-lookups', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'system_lookups',
            'types' => self::TYPES,
            'typeKey' => $key,
            'definition' => $definition,
            'records' => $records,
            'canManage' => $this->canManage($actor),
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeManage($actor);
        $key = $this->typeKey($type);
        $definition = self::TYPES[$key];
        $request->merge([
            'code' => $definition['type'] === OperationalLookupService::PRICE_TIER
                ? Str::upper(trim((string) $request->input('code')))
                : Str::lower(trim((string) $request->input('code'))),
        ]);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9_-]+$/',
                $definition['type'] === OperationalLookupService::PRICE_TIER
                    ? Rule::unique('b2b_price_tiers', 'code')
                    : Rule::unique('operational_lookups', 'code')
                        ->where(fn ($query) => $query->where('type', $definition['type'])),
            ],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($definition['type'] === OperationalLookupService::PRICE_TIER) {
            $model = B2bPriceTier::query()->create([
                'code' => Str::upper(trim((string) $data['code'])),
                'name' => (string) $data['label_en'],
                'name_ar' => (string) $data['label_ar'],
                'name_en' => (string) $data['label_en'],
                'priority' => (int) $data['sort_order'],
                'is_active' => $request->boolean('is_active', true),
            ]);
        } else {
            $model = OperationalLookup::query()->create([
                'type' => $definition['type'],
                'code' => Str::lower(trim((string) $data['code'])),
                'label_ar' => (string) $data['label_ar'],
                'label_en' => (string) $data['label_en'],
                'sort_order' => (int) $data['sort_order'],
                'is_active' => $request->boolean('is_active', true),
            ]);
        }

        $this->audit->record(
            'system_lookup.'.$definition['type'].'.created',
            $actor,
            $model,
            null,
            $model->toArray(),
            $request,
        );

        return $this->redirect($key, $this->msg('تمت إضافة القيمة المرجعية.', 'Lookup value added.'));
    }

    public function update(Request $request, string $type, int $lookup): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeManage($actor);
        $key = $this->typeKey($type);
        $definition = self::TYPES[$key];

        $data = $request->validate([
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($definition['type'] === OperationalLookupService::PRICE_TIER) {
            $model = B2bPriceTier::query()->findOrFail($lookup);
            $before = $model->toArray();
            $model->fill([
                'name' => (string) $data['label_en'],
                'name_ar' => (string) $data['label_ar'],
                'name_en' => (string) $data['label_en'],
                'priority' => (int) $data['sort_order'],
                'is_active' => $request->boolean('is_active'),
            ])->save();
        } else {
            $model = OperationalLookup::query()
                ->where('type', $definition['type'])
                ->findOrFail($lookup);
            $before = $model->toArray();
            $model->fill([
                'label_ar' => (string) $data['label_ar'],
                'label_en' => (string) $data['label_en'],
                'sort_order' => (int) $data['sort_order'],
                'is_active' => $request->boolean('is_active'),
            ])->save();
        }

        $this->audit->record(
            'system_lookup.'.$definition['type'].'.updated',
            $actor,
            $model,
            $before,
            $model->fresh()?->toArray(),
            $request,
        );

        return $this->redirect($key, $this->msg('تم تحديث القيمة المرجعية.', 'Lookup value updated.'));
    }

    private function authorizeView(User $actor): void
    {
        if ($actor->hasRole('SUPER_ADMIN') || Gate::forUser($actor)->allows('lookups.view')) {
            return;
        }

        $hasScoped = $actor->storeRoleAssignments()
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', 'lookups.view')))
            ->exists();

        abort_unless($hasScoped, 403);
    }

    private function authorizeManage(User $actor): void
    {
        Gate::forUser($actor)->authorize('lookups.manage');
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole('SUPER_ADMIN') && Gate::forUser($actor)->allows('lookups.manage');
    }

    private function typeKey(string $type): string
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        return $type;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function redirect(string $type, string $message): RedirectResponse
    {
        return redirect()->route('admin.operations.lookups.index', ['type' => $type])
            ->with('status', $message);
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
