<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformCustomer;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class Customer360Controller extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'origin_channel' => ['nullable', Rule::in(['b2b', 'b2c', 'unknown'])],
            'registration_source' => ['nullable', Rule::in(['customer_app', 'dashboard', 'import', 'migration'])],
            'active' => ['nullable', Rule::in(['0', '1'])],
            'origin_store_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = PlatformCustomer::query()->with(['user', 'originStore']);
        $this->applyVisibility($query, $access);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('platform_customers.name', 'like', "%{$search}%")
                    ->orWhere('platform_customers.email', 'like', "%{$search}%")
                    ->orWhere('platform_customers.phone', 'like', "%{$search}%");
            });
        }

        foreach (['origin_channel', 'registration_source'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $query->where('is_active', $filters['active'] === '1');
        }

        if (! empty($filters['origin_store_id'])) {
            $originStoreId = (int) $filters['origin_store_id'];
            abort_unless(in_array($originStoreId, $access['origin_store_ids'], true), 404);
            $query->where('origin_store_id', $originStoreId);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('registered_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('registered_at', '<=', $filters['date_to']);
        }

        $customers = $query
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $customers->getCollection()->transform(
            fn (PlatformCustomer $customer): array => $this->summaryRow($customer, $actor, $access),
        );

        return view('admin.customer-360-index', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'customer_360',
            'customers' => $customers,
            'filters' => $filters,
            'access' => $access,
            'originStores' => $this->originStores($access),
        ]);
    }

    public function show(Request $request, int $platformCustomer): View
    {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        $summary = $this->summaryRow($customer, $actor, $access);
        $domains = $this->domainIds($customer, $access);

        $orders = $this->ordersQuery($domains, $access)
            ->leftJoin('stores', 'stores.id', '=', 'orders.store_id')
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id')
            ->limit(30)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.store_id',
                'orders.channel',
                'orders.status',
                'orders.currency',
                'orders.grand_total',
                'orders.created_at',
                'stores.name as store_name',
            ])
            ->map(function (object $row) use ($actor): array {
                $channel = strtolower((string) $row->channel);
                $params = ['module' => 'orders', 'q' => $row->order_number];
                if ($channel === 'b2c') {
                    $params['store_id'] = (int) $row->store_id;
                    if ($actor->hasRole('SUPER_ADMIN')) {
                        $params['support_access'] = 1;
                    }
                }

                return [
                    'id' => (int) $row->id,
                    'number' => (string) $row->order_number,
                    'store' => (string) ($row->store_name ?? '-'),
                    'channel' => $channel,
                    'status' => (string) $row->status,
                    'currency' => (string) $row->currency,
                    'total' => (float) $row->grand_total,
                    'created_at' => $row->created_at,
                    'url' => route("admin.{$channel}.module", $params),
                ];
            })
            ->all();

        $invoices = $this->invoicesQuery($customer, $domains, $access)
            ->leftJoin('stores', 'stores.id', '=', 'invoices.store_id')
            ->orderByDesc('invoices.issued_at')
            ->orderByDesc('invoices.id')
            ->limit(30)
            ->get([
                'invoices.id',
                'invoices.invoice_number',
                'invoices.store_id',
                'invoices.channel',
                'invoices.status',
                'invoices.currency',
                'invoices.total',
                'invoices.issued_at',
                'stores.name as store_name',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->invoice_number,
                'store' => (string) ($row->store_name ?? '-'),
                'channel' => strtolower((string) $row->channel),
                'status' => (string) $row->status,
                'currency' => (string) $row->currency,
                'total' => (float) $row->total,
                'issued_at' => $row->issued_at,
                'url' => route('admin.invoices.show', ['invoice' => $row->id]),
                'pdf_url' => route('admin.invoices.download', ['invoice' => $row->id, 'locale' => app()->getLocale()]),
            ])
            ->all();

        return view('admin.customer-360-show', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'customer_360',
            'customer' => $customer,
            'summary' => $summary,
            'access' => $access,
            'orders' => $orders,
            'invoices' => $invoices,
            'retailStores' => $this->retailDomains($customer, $access),
            'wholesale' => $this->wholesaleInfo($customer, $access),
        ]);
    }

    /** @param array{mode:string,b2b_store_ids:list<int>,b2c_store_ids:list<int>,origin_store_ids:list<int>} $access */
    private function applyVisibility($query, array $access): void
    {
        if ($access['mode'] === 'platform') {
            return;
        }

        if ($access['mode'] === 'b2b') {
            $query->whereIn('platform_customers.user_id', DB::table('b2b_customers')
                ->select('user_id')
                ->whereNotNull('user_id'));

            return;
        }

        $query->whereIn('platform_customers.user_id', DB::table('b2c_customers')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->whereIn('store_id', $access['b2c_store_ids']));
    }

    /** @return array{mode:string,b2b_store_ids:list<int>,b2c_store_ids:list<int>,origin_store_ids:list<int>} */
    private function access(User $actor): array
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            $b2b = $this->scope->allowedStoreIds($actor, 'b2b.accounts.view', 'b2b');
            $b2c = $this->scope->allowedStoreIds($actor, 'customers.view', 'b2c');

            return [
                'mode' => 'platform',
                'b2b_store_ids' => $b2b,
                'b2c_store_ids' => $b2c,
                'origin_store_ids' => array_values(array_unique([...$b2b, ...$b2c])),
            ];
        }

        if ($this->navigation->canAccess($actor, 'b2b') && $actor->hasPermission('b2b.accounts.view')) {
            $b2b = $this->scope->allowedStoreIds($actor, 'b2b.accounts.view', 'b2b');

            return [
                'mode' => 'b2b',
                'b2b_store_ids' => $b2b,
                'b2c_store_ids' => [],
                'origin_store_ids' => $b2b,
            ];
        }

        $b2c = $this->scope->allowedStoreIds($actor, 'customers.view', 'b2c');
        abort_if($b2c === [], 403);

        return [
            'mode' => 'b2c',
            'b2b_store_ids' => [],
            'b2c_store_ids' => $b2c,
            'origin_store_ids' => $b2c,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @param array{mode:string,b2b_store_ids:list<int>,b2c_store_ids:list<int>,origin_store_ids:list<int>} $access */
    private function findVisible(int $id, array $access): PlatformCustomer
    {
        $query = PlatformCustomer::query()->with(['user', 'originStore'])->whereKey($id);
        $this->applyVisibility($query, $access);

        return $query->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function summaryRow(PlatformCustomer $customer, User $actor, array $access): array
    {
        $domains = $this->domainIds($customer, $access);
        $orders = $this->ordersQuery($domains, $access);
        $invoices = $this->invoicesQuery($customer, $domains, $access);
        $origin = $this->originPresentation($customer, $access);
        $wholesale = $this->wholesaleInfo($customer, $access);

        return [
            'id' => (int) $customer->getKey(),
            'name' => (string) $customer->name,
            'email' => (string) $customer->email,
            'phone' => $customer->phone,
            'active' => (bool) $customer->is_active && (bool) ($customer->user?->is_active ?? true),
            'origin' => $origin,
            'registration_source' => (string) $customer->registration_source,
            'registered_at' => $customer->registered_at,
            'orders_count' => (clone $orders)->count(),
            'orders_total' => (float) ((clone $orders)->sum('orders.grand_total') ?? 0),
            'invoices_count' => (clone $invoices)->count(),
            'invoices_total' => (float) ((clone $invoices)->sum('invoices.total') ?? 0),
            'wholesale_tier' => $wholesale['tier_name'] ?? null,
            'url' => route('admin.customer-360.show', ['platformCustomer' => $customer->getKey()]),
        ];
    }

    /** @return array{b2b_id:?int,b2c_ids:list<int>} */
    private function domainIds(PlatformCustomer $customer, array $access): array
    {
        $b2bId = null;
        if ($access['mode'] !== 'b2c') {
            $value = DB::table('b2b_customers')->where('user_id', $customer->user_id)->value('id');
            $b2bId = $value === null ? null : (int) $value;
        }

        $b2c = DB::table('b2c_customers')
            ->where('user_id', $customer->user_id)
            ->when($access['mode'] === 'b2c', fn ($q) => $q->whereIn('store_id', $access['b2c_store_ids']))
            ->when($access['mode'] === 'b2b', fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return ['b2b_id' => $b2bId, 'b2c_ids' => $b2c];
    }

    /** @param array{b2b_id:?int,b2c_ids:list<int>} $domains */
    private function ordersQuery(array $domains, array $access): QueryBuilder
    {
        $query = DB::table('orders');

        if ($access['mode'] === 'b2b') {
            if ($domains['b2b_id'] === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query
                ->where('orders.channel', 'b2b')
                ->where('orders.b2b_customer_id', $domains['b2b_id'])
                ->whereIn('orders.store_id', $access['b2b_store_ids']);
        }

        if ($access['mode'] === 'b2c') {
            if ($domains['b2c_ids'] === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query
                ->where('orders.channel', 'b2c')
                ->whereIn('orders.b2c_customer_id', $domains['b2c_ids'])
                ->whereIn('orders.store_id', $access['b2c_store_ids']);
        }

        return $query->where(function ($q) use ($domains): void {
            if ($domains['b2b_id'] !== null) {
                $q->orWhere('orders.b2b_customer_id', $domains['b2b_id']);
            }
            if ($domains['b2c_ids'] !== []) {
                $q->orWhereIn('orders.b2c_customer_id', $domains['b2c_ids']);
            }
            if ($domains['b2b_id'] === null && $domains['b2c_ids'] === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** @param array{b2b_id:?int,b2c_ids:list<int>} $domains */
    private function invoicesQuery(PlatformCustomer $customer, array $domains, array $access): QueryBuilder
    {
        $query = DB::table('invoices')->where(function ($q) use ($customer, $domains): void {
            $q->where('invoices.platform_customer_id', $customer->getKey());
            if ($domains['b2b_id'] !== null) {
                $q->orWhere('invoices.b2b_customer_id', $domains['b2b_id']);
            }
            if ($domains['b2c_ids'] !== []) {
                $q->orWhereIn('invoices.b2c_customer_id', $domains['b2c_ids']);
            }
        });

        if ($access['mode'] === 'b2b') {
            return $query->where('invoices.channel', 'b2b')->whereIn('invoices.store_id', $access['b2b_store_ids']);
        }
        if ($access['mode'] === 'b2c') {
            return $query->where('invoices.channel', 'b2c')->whereIn('invoices.store_id', $access['b2c_store_ids']);
        }

        return $query;
    }

    /** @return array<string,mixed>|null */
    private function wholesaleInfo(PlatformCustomer $customer, array $access): ?array
    {
        if ($access['mode'] === 'b2c') {
            return null;
        }

        $row = DB::table('b2b_customers')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
            ->where('b2b_customers.user_id', $customer->user_id)
            ->first([
                'b2b_customers.id as customer_id',
                'b2b_accounts.id as account_id',
                'b2b_accounts.company_name',
                'b2b_accounts.status',
                'b2b_accounts.credit_limit',
                'b2b_price_tiers.id as tier_id',
                'b2b_price_tiers.code as tier_code',
                'b2b_price_tiers.name as tier_name',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'customer_id' => (int) $row->customer_id,
            'account_id' => $row->account_id === null ? null : (int) $row->account_id,
            'company_name' => $row->company_name,
            'status' => $row->status,
            'credit_limit' => $row->credit_limit === null ? null : (float) $row->credit_limit,
            'tier_id' => $row->tier_id === null ? null : (int) $row->tier_id,
            'tier_code' => $row->tier_code,
            'tier_name' => $row->tier_name,
        ];
    }

    /** @return list<array{id:int,name:string,code:string}> */
    private function retailDomains(PlatformCustomer $customer, array $access): array
    {
        if ($access['mode'] === 'b2b') {
            return [];
        }

        return DB::table('b2c_customers')
            ->join('stores', 'stores.id', '=', 'b2c_customers.store_id')
            ->where('b2c_customers.user_id', $customer->user_id)
            ->when($access['mode'] === 'b2c', fn ($q) => $q->whereIn('b2c_customers.store_id', $access['b2c_store_ids']))
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name', 'stores.code'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
            ])
            ->all();
    }

    /** @return array{channel:string,label:string,exact:bool} */
    private function originPresentation(PlatformCustomer $customer, array $access): array
    {
        $channel = strtolower((string) ($customer->origin_channel ?: 'unknown'));
        $storeId = $customer->origin_store_id === null ? null : (int) $customer->origin_store_id;
        $canRevealStore = $storeId !== null && (
            $access['mode'] === 'platform'
            || in_array($storeId, $access['origin_store_ids'], true)
        );

        if ($canRevealStore && $customer->originStore !== null) {
            return [
                'channel' => $channel,
                'label' => $customer->originStore->name.' · '.$customer->originStore->code,
                'exact' => true,
            ];
        }

        if ($channel === 'b2b') {
            return ['channel' => 'b2b', 'label' => $this->msg('الجملة', 'Wholesale'), 'exact' => false];
        }
        if ($channel === 'b2c') {
            return [
                'channel' => 'b2c',
                'label' => $access['mode'] === 'b2c'
                    ? $this->msg('مسجل من مصدر تجزئة آخر', 'Registered via another Retail source')
                    : $this->msg('التجزئة', 'Retail'),
                'exact' => false,
            ];
        }

        return ['channel' => 'unknown', 'label' => $this->msg('مصدر قديم / غير معروف', 'Legacy / unknown source'), 'exact' => false];
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function originStores(array $access)
    {
        if ($access['origin_store_ids'] === []) {
            return collect();
        }

        return DB::table('stores')
            ->whereIn('id', $access['origin_store_ids'])
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
