<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\PlatformCustomer;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\OperationalTenantScope;
use App\Services\RetailMerchantIdentityService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

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

    public function invalidReference(string $invalidCustomerReference): RedirectResponse
    {
        return redirect()->route('admin.customer-360.index');
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
                    'channel_label' => $this->businessLabel($channel),
                    'status' => (string) $row->status,
                    'status_label' => $this->businessLabel((string) $row->status),
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
                'channel_label' => $this->businessLabel(strtolower((string) $row->channel)),
                'status' => (string) $row->status,
                'status_label' => $this->businessLabel((string) $row->status),
                'currency' => (string) $row->currency,
                'total' => (float) $row->total,
                'issued_at' => $row->issued_at,
                'url' => route('admin.invoices.show', ['invoice' => $row->id]),
                'pdf_url' => route('admin.invoices.download', ['invoice' => $row->id, 'locale' => app()->getLocale()]),
            ])
            ->all();

        $addresses = $this->addressQuery($customer)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
        $wholesale = $this->wholesaleInfo($customer, $access);

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
            'wholesale' => $wholesale,
            'canManageFinance' => $wholesale !== null && ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('finance.manage')),
            'addresses' => $addresses,
            'canManageAddresses' => $this->canManageAddresses($actor, $customer, $access),
        ]);
    }

    public function updateCreditLimit(
        Request $request,
        int $platformCustomer,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);

        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('finance.manage'), 403);
        abort_if($access['mode'] === 'b2c', 403);

        $domain = $this->wholesaleDomain($customer, $access);
        abort_unless($domain instanceof B2bCustomer, 404);

        $validated = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:99999999999.999'],
        ]);
        $creditLimit = round((float) $validated['credit_limit'], 3);

        [$account, $previousCreditLimit] = DB::transaction(function () use ($domain, $creditLimit): array {
            $account = B2bAccount::query()
                ->where('b2b_customer_id', $domain->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $previousCreditLimit = round((float) $account->credit_limit, 3);
            $account->credit_limit = $creditLimit;
            $account->save();

            return [$account, $previousCreditLimit];
        }, 3);

        $before = ['credit_limit' => $previousCreditLimit];
        $after = ['credit_limit' => round((float) $account->credit_limit, 3)];

        $audit->record(
            'customer360.credit_limit_updated',
            $actor,
            $account,
            $before,
            $after,
            $request,
        );

        return redirect()
            ->to(route('admin.customer-360.show', ['platformCustomer' => $customer->getKey()]).'#finance')
            ->with('status', $this->msg('تم تحديث الحد الائتماني.', 'Credit limit updated.'));
    }

    public function storeFinanceEntry(
        Request $request,
        int $platformCustomer,
        B2bAccountLedgerService $ledger,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('finance.manage'), 403);
        abort_if($access['mode'] === 'b2c', 403);

        $domain = $this->wholesaleDomain($customer, $access);
        abort_unless($domain instanceof B2bCustomer, 404);

        $validated = $request->validate([
            'entry_type' => ['required', Rule::in(B2bAccountLedgerService::MANUAL_TYPES)],
            'direction' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $amount = round((float) $validated['amount'], 3);
        $entryId = $ledger->appendManual($domain, [
            'entry_type' => $validated['entry_type'],
            'debit' => $validated['direction'] === 'debit' ? $amount : 0,
            'credit' => $validated['direction'] === 'credit' ? $amount : 0,
            'currency' => strtoupper((string) $validated['currency']),
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'invoice_id' => $validated['invoice_id'] ?? null,
            'occurred_at' => $validated['occurred_at'] ?? now(),
            'source' => 'dashboard_customer_360',
        ], $actor);

        $audit->record(
            'customer360.finance_entry_created',
            $actor,
            $customer,
            null,
            [
                'ledger_entry_id' => $entryId,
                'b2b_customer_id' => (int) $domain->getKey(),
                'entry_type' => (string) $validated['entry_type'],
                'direction' => (string) $validated['direction'],
                'amount' => $amount,
                'currency' => strtoupper((string) $validated['currency']),
            ],
            $request,
        );

        return redirect()
            ->route('admin.customer-360.show', ['platformCustomer' => $customer->getKey()])
            ->with('status', $this->msg('تم تسجيل الحركة المالية.', 'Financial entry recorded.'));
    }

    public function storeAddress(
        Request $request,
        int $platformCustomer,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        $this->assertCanManageAddresses($actor, $customer, $access);
        $validated = $request->validate($this->addressRules(false));
        $this->assertLocationSemantics($validated);

        $address = DB::transaction(function () use ($customer, $validated): Address {
            $this->addressQuery($customer)->lockForUpdate()->get();
            $shouldDefault = (bool) ($validated['is_default'] ?? false)
                || ! $this->addressQuery($customer)->exists();

            if ($shouldDefault) {
                $this->addressQuery($customer)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            return Address::query()->create([
                'customer_id' => (int) $customer->legacy_customer_id,
                'platform_customer_id' => (int) $customer->getKey(),
                'b2b_customer_id' => null,
                'b2c_customer_id' => null,
                ...$this->normalizedAddress($validated),
                'is_default' => $shouldDefault,
            ]);
        }, 3);

        $audit->record(
            'customer360.address_created',
            $actor,
            $address,
            null,
            ['platform_customer_id' => (int) $customer->getKey()],
            $request,
        );

        return $this->addressRedirect($customer, $this->msg('تمت إضافة العنوان.', 'Address added.'));
    }

    public function updateAddress(
        Request $request,
        int $platformCustomer,
        int $address,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        $this->assertCanManageAddresses($actor, $customer, $access);
        $validated = $request->validate($this->addressRules(true));
        $model = $this->addressQuery($customer)->whereKey($address)->firstOrFail();
        $this->assertLocationSemantics($validated, $model);
        $before = $model->only([
            'label', 'line1', 'city', 'area', 'country_code',
            'latitude', 'longitude', 'is_default',
        ]);

        DB::transaction(function () use ($customer, $model, $validated): void {
            $this->addressQuery($customer)->lockForUpdate()->get();
            $locked = $this->addressQuery($customer)->whereKey($model->getKey())->firstOrFail();

            if (($validated['is_default'] ?? false) === true) {
                $this->addressQuery($customer)
                    ->whereKeyNot($locked->getKey())
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $locked->fill($this->normalizedAddress($validated));
            if (array_key_exists('is_default', $validated)) {
                $locked->is_default = (bool) $validated['is_default'];
            }
            $locked->save();

            if (! $this->addressQuery($customer)->where('is_default', true)->exists()) {
                $fallback = $this->addressQuery($customer)->orderBy('id')->first();
                $fallback?->update(['is_default' => true]);
            }
        }, 3);

        $model->refresh();
        $audit->record(
            'customer360.address_updated',
            $actor,
            $model,
            $before,
            $model->only([
                'label', 'line1', 'city', 'area', 'country_code',
                'latitude', 'longitude', 'is_default',
            ]),
            $request,
        );

        return $this->addressRedirect($customer, $this->msg('تم تحديث العنوان.', 'Address updated.'));
    }

    public function setDefaultAddress(
        Request $request,
        int $platformCustomer,
        int $address,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        $this->assertCanManageAddresses($actor, $customer, $access);
        $model = $this->addressQuery($customer)->whereKey($address)->firstOrFail();

        DB::transaction(function () use ($customer, $model): void {
            $this->addressQuery($customer)->lockForUpdate()->get();
            $this->addressQuery($customer)
                ->whereKeyNot($model->getKey())
                ->update(['is_default' => false, 'updated_at' => now()]);
            $this->addressQuery($customer)
                ->whereKey($model->getKey())
                ->update(['is_default' => true, 'updated_at' => now()]);
        }, 3);

        $model->refresh();
        $audit->record(
            'customer360.address_default_changed',
            $actor,
            $model,
            null,
            ['is_default' => true, 'platform_customer_id' => (int) $customer->getKey()],
            $request,
        );

        return $this->addressRedirect($customer, $this->msg('تم تعيين العنوان الافتراضي.', 'Default address updated.'));
    }

    public function destroyAddress(
        Request $request,
        int $platformCustomer,
        int $address,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $access = $this->access($actor);
        $customer = $this->findVisible($platformCustomer, $access);
        $this->assertCanManageAddresses($actor, $customer, $access);
        $model = $this->addressQuery($customer)->whereKey($address)->firstOrFail();
        $wasDefault = (bool) $model->is_default;

        DB::transaction(function () use ($customer, $model, $wasDefault): void {
            $this->addressQuery($customer)->lockForUpdate()->get();
            $model->delete();

            if ($wasDefault) {
                $fallback = $this->addressQuery($customer)->orderBy('id')->first();
                $fallback?->update(['is_default' => true]);
            }
        }, 3);

        $audit->record(
            'customer360.address_deleted',
            $actor,
            $model,
            ['platform_customer_id' => (int) $customer->getKey()],
            null,
            $request,
        );

        return $this->addressRedirect($customer, $this->msg('تم حذف العنوان.', 'Address deleted.'));
    }

    /** @return Builder<Address> */
    private function addressQuery(PlatformCustomer $customer)
    {
        return Address::query()->where(function ($query) use ($customer): void {
            $query->where('platform_customer_id', $customer->getKey())
                ->orWhere(function ($legacy) use ($customer): void {
                    $legacy->whereNull('platform_customer_id')
                        ->where('customer_id', $customer->legacy_customer_id);
                });
        });
    }

    /** @return array<string, array<int, string>> */
    private function addressRules(bool $partial): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'line1' => [...$required, 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => [...$required, 'string', 'max:120'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => [...$required, 'string', 'size:2'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'governorate' => ['sometimes', 'nullable', 'string', 'max:120'],
            'block' => ['sometimes', 'nullable', 'string', 'max:120'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avenue' => ['sometimes', 'nullable', 'string', 'max:120'],
            'building' => ['sometimes', 'nullable', 'string', 'max:120'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'apartment' => ['sometimes', 'nullable', 'string', 'max:120'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_accuracy_meters' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'location_source' => ['sometimes', Rule::in(['manual', 'current_location', 'map_pin'])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Keep Dashboard-created geographic addresses subject to the same
     * invariants as customer self-service addresses.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertLocationSemantics(array $validated, ?Address $existing = null): void
    {
        $source = array_key_exists('location_source', $validated)
            ? (string) $validated['location_source']
            : (string) ($existing?->location_source ?: 'manual');

        $latitude = array_key_exists('latitude', $validated)
            ? $validated['latitude']
            : $existing?->latitude;
        $longitude = array_key_exists('longitude', $validated)
            ? $validated['longitude']
            : $existing?->longitude;

        if (in_array($source, ['current_location', 'map_pin'], true)
            && ($latitude === null || $longitude === null)) {
            throw ValidationException::withMessages([
                'latitude' => ['Latitude and longitude are required for the selected location source.'],
                'longitude' => ['Latitude and longitude are required for the selected location source.'],
            ]);
        }

        if (array_key_exists('location_accuracy_meters', $validated)
            && $validated['location_accuracy_meters'] !== null
            && ($latitude === null || $longitude === null)) {
            throw ValidationException::withMessages([
                'location_accuracy_meters' => ['Location accuracy requires a saved coordinate pair.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function normalizedAddress(array $validated): array
    {
        $values = [];
        foreach ([
            'label', 'recipient_name', 'delivery_phone', 'line1', 'line2',
            'city', 'area', 'country', 'governorate', 'block', 'street',
            'avenue', 'building', 'floor', 'apartment', 'landmark',
            'delivery_notes', 'latitude', 'longitude',
            'location_accuracy_meters', 'location_source',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $values[$field] = $validated[$field];
            }
        }

        if (array_key_exists('country_code', $validated)) {
            $values['country_code'] = Str::upper((string) $validated['country_code']);
        }
        if (array_key_exists('line1', $values) && ! array_key_exists('street', $values)) {
            $values['street'] = $values['line1'];
        }

        return $values;
    }

    private function canManageAddresses(User $actor, PlatformCustomer $customer, array $access): bool
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($access['mode'] === 'b2b') {
            return $this->scope->allowedStoreIds($actor, 'b2b.accounts.manage', 'b2b') !== [];
        }

        $allowed = $this->scope->allowedStoreIds($actor, 'customers.manage', 'b2c');
        if ($allowed === []) {
            return false;
        }

        return DB::table('b2c_customers')
            ->where('user_id', $customer->user_id)
            ->whereIn('store_id', $allowed)
            ->exists();
    }

    private function assertCanManageAddresses(User $actor, PlatformCustomer $customer, array $access): void
    {
        abort_unless($this->canManageAddresses($actor, $customer, $access), 403);
    }

    private function addressRedirect(PlatformCustomer $customer, string $message): RedirectResponse
    {
        return redirect(
            route('admin.customer-360.show', ['platformCustomer' => $customer->getKey()]).'#addresses',
        )->with('status', $message);
    }

    /** @param array{mode:string,b2b_store_ids:list<int>,b2c_store_ids:list<int>,origin_store_ids:list<int>} $access */
    private function applyVisibility($query, array $access): void
    {
        if ($access['mode'] === 'platform') {
            return;
        }

        if ($access['mode'] === 'b2b') {
            $query->where(function ($visible): void {
                $visible->whereIn(
                    'platform_customers.user_id',
                    DB::table('b2b_customers')
                        ->select('user_id')
                        ->whereNotNull('user_id'),
                )->orWhereIn(
                    'platform_customers.user_id',
                    DB::table('retail_wholesale_accounts')
                        ->join(
                            'b2b_accounts',
                            'b2b_accounts.b2b_customer_id',
                            '=',
                            'retail_wholesale_accounts.b2b_customer_id',
                        )
                        ->select('retail_wholesale_accounts.owner_user_id')
                        ->whereNotNull('retail_wholesale_accounts.owner_user_id')
                        ->where('b2b_accounts.status', 'active'),
                );
            });

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
        $relatedUser = $customer->user;
        $userIsActive = $relatedUser instanceof User ? $relatedUser->is_active : true;

        return [
            'id' => (int) $customer->getKey(),
            'name' => (string) $customer->name,
            'email' => (string) $customer->email,
            'phone' => $customer->phone,
            'active' => (bool) $customer->is_active && $userIsActive,
            'origin' => $origin,
            'registration_source' => (string) $customer->registration_source,
            'registration_source_label' => $this->businessLabel((string) $customer->registration_source),
            'origin_channel_label' => $this->businessLabel((string) ($customer->origin_channel ?: 'unknown')),
            'registered_at' => $customer->registered_at,
            'orders_count' => (clone $orders)->count(),
            'orders_total' => (float) (clone $orders)->sum('orders.grand_total'),
            'invoices_count' => (clone $invoices)->count(),
            'invoices_total' => (float) (clone $invoices)->sum('invoices.total'),
            'wholesale_tier' => $wholesale['tier_name'] ?? null,
            'url' => route('admin.customer-360.show', ['platformCustomer' => $customer->getKey()]),
        ];
    }

    private function wholesaleDomain(PlatformCustomer $customer, array $access): ?B2bCustomer
    {
        if ($access['mode'] === 'b2c') {
            return null;
        }

        $user = $customer->user;
        if (! $user instanceof User) {
            return null;
        }

        return app(CustomerDomainResolver::class)->existingB2b($user);
    }

    /** @return array{b2b_id:?int,b2c_ids:list<int>} */
    private function domainIds(PlatformCustomer $customer, array $access): array
    {
        $domain = $this->wholesaleDomain($customer, $access);
        $b2bId = $domain instanceof B2bCustomer ? (int) $domain->getKey() : null;

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
        $domain = $this->wholesaleDomain($customer, $access);
        if (! $domain instanceof B2bCustomer) {
            return null;
        }

        $row = DB::table('b2b_customers')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
            ->where('b2b_customers.id', $domain->getKey())
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

        $financial = strtolower((string) $row->status) === 'active'
            ? app(B2bAccountLedgerService::class)->summary($domain)
            : null;

        return [
            'customer_id' => (int) $row->customer_id,
            'account_id' => $row->account_id === null ? null : (int) $row->account_id,
            'company_name' => $row->company_name,
            'status' => $row->status,
            'status_label' => $this->businessLabel((string) $row->status),
            'credit_limit' => $row->credit_limit === null ? null : (float) $row->credit_limit,
            'tier_id' => $row->tier_id === null ? null : (int) $row->tier_id,
            'tier_code' => $row->tier_code,
            'tier_name' => $row->tier_name,
            'financial' => $financial,
        ];
    }

    private function businessLabel(string $value): string
    {
        $key = strtolower(trim($value));
        if ($key === '') {
            return '—';
        }

        $translationKey = 'customer_360.business_labels.'.$key;
        $translated = __($translationKey);

        return $translated === $translationKey
            ? Str::headline($key)
            : $translated;
    }

    /** @return list<array{id:int,name:string,code:string}> */
    private function retailDomains(PlatformCustomer $customer, array $access): array
    {
        if ($access['mode'] === 'b2b') {
            return [];
        }

        $storeIds = DB::table('b2c_customers')
            ->where('user_id', $customer->user_id)
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $user = $customer->user;
        if ($user instanceof User) {
            $storeIds = [
                ...$storeIds,
                ...app(RetailMerchantIdentityService::class)->retailStoreIds($user),
            ];
        }

        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        if ($access['mode'] === 'b2c') {
            $storeIds = array_values(array_intersect($storeIds, $access['b2c_store_ids']));
        }

        if ($storeIds === []) {
            return [];
        }

        return DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
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

        $originStore = $customer->originStore;
        if ($canRevealStore && $originStore instanceof Store) {
            return [
                'channel' => $channel,
                'label' => $originStore->name.' · '.$originStore->code,
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

    /** @return Collection<int, stdClass> */
    private function originStores(array $access): Collection
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
        return App::getLocale() === 'ar' ? $ar : $en;
    }
}
