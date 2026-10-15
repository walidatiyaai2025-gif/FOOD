<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommerceQuoteService;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreasuryMasterController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $tenantScope,
        private readonly OrganizationHierarchyService $hierarchy,
        private readonly CommerceQuoteService $quotes,
        private readonly AuditLogger $audit,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['cashboxes', 'bank_accounts'])],
            'q' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'create' => ['nullable', Rule::in(['cashbox', 'bank_account'])],
            'edit_cashbox' => ['nullable', 'integer', 'min:1'],
            'edit_bank_account' => ['nullable', 'integer', 'min:1'],
        ]);

        $viewStoreIds = $this->tenantScope->allowedStoreIds($actor, 'finance.view');
        abort_if($viewStoreIds === [], 403);

        $manageStoreIds = $this->tenantScope->allowedStoreIds($actor, 'finance.manage');
        $requestedStoreId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        if ($requestedStoreId !== null) {
            abort_unless(in_array($requestedStoreId, $viewStoreIds, true), 404);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cashboxes = $this->filterQuery(
            EnterpriseCashbox::query()->with(['company:id,name', 'store:id,name']),
            $viewStoreIds,
            $requestedStoreId,
            $search,
            $status,
            ['code', 'name', 'currency_code'],
        )->orderBy('code')->paginate($perPage, ['*'], 'cashboxes_page')->withQueryString();

        $bankAccounts = $this->filterQuery(
            EnterpriseBankAccount::query()->with(['company:id,name', 'store:id,name']),
            $viewStoreIds,
            $requestedStoreId,
            $search,
            $status,
            ['code', 'name', 'bank_name', 'account_name', 'account_reference_last4', 'currency_code'],
        )->orderBy('code')->paginate($perPage, ['*'], 'bank_accounts_page')->withQueryString();

        $editingCashbox = isset($filters['edit_cashbox'])
            ? EnterpriseCashbox::query()
                ->whereIn('store_id', $manageStoreIds)
                ->findOrFail((int) $filters['edit_cashbox'])
            : null;

        $editingBankAccount = isset($filters['edit_bank_account'])
            ? EnterpriseBankAccount::query()
                ->whereIn('store_id', $manageStoreIds)
                ->findOrFail((int) $filters['edit_bank_account'])
            : null;

        return view('admin.treasury-master', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'treasury_master',
            'filters' => [
                ...$filters,
                'tab' => (string) ($filters['tab'] ?? 'cashboxes'),
                'status' => $status,
                'per_page' => $perPage,
            ],
            'cashboxes' => $cashboxes,
            'bankAccounts' => $bankAccounts,
            'stores' => Store::query()->whereIn('id', $viewStoreIds)->orderBy('name')->get(['id', 'name']),
            'manageableStores' => Store::query()->whereIn('id', $manageStoreIds)->orderBy('name')->get(['id', 'name']),
            'manageStoreIds' => $manageStoreIds,
            'editingCashbox' => $editingCashbox,
            'editingBankAccount' => $editingBankAccount,
        ]);
    }

    public function storeCashbox(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->validatedMaster($request);
        $context = $this->contextForStore($actor, (int) $data['store_id']);
        $code = $this->normalizedCode((string) $data['code']);

        $this->assertCodeAvailable('enterprise_cashboxes', $context['company_id'], (int) $data['store_id'], $code);

        DB::transaction(function () use ($actor, $request, $data, $context, $code): void {
            $cashbox = EnterpriseCashbox::query()->create([
                ...$this->masterAttributes($data, $context, $code),
            ]);

            $this->audit->record(
                'enterprise.treasury.cashbox.created',
                $actor,
                $cashbox,
                null,
                $cashbox->toArray(),
                $request,
            );
        });

        return redirect()->route('admin.treasury.master.index', ['tab' => 'cashboxes'])
            ->with('status', __('treasury.messages.cashbox_saved'));
    }

    public function updateCashbox(Request $request, int $cashbox): RedirectResponse
    {
        $actor = $this->actor($request);
        $allowed = $this->tenantScope->allowedStoreIds($actor, 'finance.manage');
        $model = EnterpriseCashbox::query()->whereIn('store_id', $allowed)->findOrFail($cashbox);
        $data = $this->validatedMaster($request);
        $context = $this->contextForStore($actor, (int) $data['store_id']);
        $code = $this->normalizedCode((string) $data['code']);

        $this->assertCodeAvailable('enterprise_cashboxes', $context['company_id'], (int) $data['store_id'], $code, $model->getKey());

        DB::transaction(function () use ($actor, $request, $data, $context, $code, $model): void {
            $before = $model->toArray();
            $model->fill($this->masterAttributes($data, $context, $code))->save();
            $fresh = $model->refresh();

            $this->audit->record(
                'enterprise.treasury.cashbox.updated',
                $actor,
                $fresh,
                $before,
                $fresh->toArray(),
                $request,
            );
        });

        return redirect()->route('admin.treasury.master.index', ['tab' => 'cashboxes'])
            ->with('status', __('treasury.messages.cashbox_saved'));
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->validatedBankAccount($request, true);
        $context = $this->contextForStore($actor, (int) $data['store_id']);
        $code = $this->normalizedCode((string) $data['code']);

        $this->assertCodeAvailable('enterprise_bank_accounts', $context['company_id'], (int) $data['store_id'], $code);
        [$reference, $last4] = $this->referenceParts((string) $data['account_reference']);

        DB::transaction(function () use ($actor, $request, $data, $context, $code, $reference, $last4): void {
            $bank = EnterpriseBankAccount::query()->create([
                ...$this->masterAttributes($data, $context, $code),
                'bank_name' => trim((string) $data['bank_name']),
                'account_name' => trim((string) $data['account_name']),
                'account_reference' => $reference,
                'account_reference_last4' => $last4,
            ]);

            $this->audit->record(
                'enterprise.treasury.bank_account.created',
                $actor,
                $bank,
                null,
                $bank->toArray(),
                $request,
            );
        });

        return redirect()->route('admin.treasury.master.index', ['tab' => 'bank_accounts'])
            ->with('status', __('treasury.messages.bank_account_saved'));
    }

    public function updateBankAccount(Request $request, int $bankAccount): RedirectResponse
    {
        $actor = $this->actor($request);
        $allowed = $this->tenantScope->allowedStoreIds($actor, 'finance.manage');
        $model = EnterpriseBankAccount::query()->whereIn('store_id', $allowed)->findOrFail($bankAccount);
        $data = $this->validatedBankAccount($request, false);
        $context = $this->contextForStore($actor, (int) $data['store_id']);
        $code = $this->normalizedCode((string) $data['code']);

        $this->assertCodeAvailable('enterprise_bank_accounts', $context['company_id'], (int) $data['store_id'], $code, $model->getKey());

        DB::transaction(function () use ($actor, $request, $data, $context, $code, $model): void {
            $before = $model->toArray();
            $attributes = [
                ...$this->masterAttributes($data, $context, $code),
                'bank_name' => trim((string) $data['bank_name']),
                'account_name' => trim((string) $data['account_name']),
            ];

            if (isset($data['account_reference']) && trim((string) $data['account_reference']) !== '') {
                [$reference, $last4] = $this->referenceParts((string) $data['account_reference']);
                $attributes['account_reference'] = $reference;
                $attributes['account_reference_last4'] = $last4;
            }

            $model->fill($attributes)->save();
            $fresh = $model->refresh();

            $this->audit->record(
                'enterprise.treasury.bank_account.updated',
                $actor,
                $fresh,
                $before,
                $fresh->toArray(),
                $request,
            );
        });

        return redirect()->route('admin.treasury.master.index', ['tab' => 'bank_accounts'])
            ->with('status', __('treasury.messages.bank_account_saved'));
    }

    /** @return array<string, mixed> */
    private function validatedMaster(Request $request): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:180'],
            'operator_visibility' => ['required', Rule::in([EnterpriseCashbox::VISIBILITY_STORE, EnterpriseCashbox::VISIBILITY_COMPANY])],
            'approval_visibility' => ['required', Rule::in([EnterpriseCashbox::VISIBILITY_STORE, EnterpriseCashbox::VISIBILITY_COMPANY])],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedBankAccount(Request $request, bool $creating): array
    {
        return $request->validate([
            ...$this->validatedRules(),
            'bank_name' => ['required', 'string', 'max:180'],
            'account_name' => ['required', 'string', 'max:180'],
            'account_reference' => [$creating ? 'required' : 'nullable', 'string', 'min:4', 'max:180'],
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function validatedRules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:180'],
            'operator_visibility' => ['required', Rule::in([EnterpriseBankAccount::VISIBILITY_STORE, EnterpriseBankAccount::VISIBILITY_COMPANY])],
            'approval_visibility' => ['required', Rule::in([EnterpriseBankAccount::VISIBILITY_STORE, EnterpriseBankAccount::VISIBILITY_COMPANY])],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @param Builder<EnterpriseCashbox>|Builder<EnterpriseBankAccount> $query
     * @param list<int> $storeIds
     * @param list<string> $searchColumns
     * @return Builder<EnterpriseCashbox>|Builder<EnterpriseBankAccount>
     */
    private function filterQuery(
        Builder $query,
        array $storeIds,
        ?int $storeId,
        string $search,
        string $status,
        array $searchColumns,
    ): Builder
    {
        $query->whereIn('store_id', $storeIds);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search !== '') {
            $query->where(function (Builder $nested) use ($search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $nested->{$method}($column, 'like', '%'.$search.'%');
                }
            });
        }

        return $query;
    }

    /** @return array{company_id:int,currency_code:string} */
    private function contextForStore(User $actor, int $storeId): array
    {
        $this->tenantScope->assertStore($actor, $storeId, 'finance.manage');
        $scope = $this->hierarchy->scopeForStore($storeId);
        if ($scope === null) {
            throw ValidationException::withMessages([
                'store_id' => [__('treasury.errors.hierarchy_missing')],
            ]);
        }

        return [
            'company_id' => $scope['company_id'],
            'currency_code' => $this->quotes->currencyForStore($storeId),
        ];
    }

    /** @param array<string,mixed> $data
     * @param array{company_id:int,currency_code:string} $context
     * @return array<string,mixed>
     */
    private function masterAttributes(array $data, array $context, string $code): array
    {
        return [
            'company_id' => $context['company_id'],
            'store_id' => (int) $data['store_id'],
            'code' => $code,
            'name' => trim((string) $data['name']),
            'currency_code' => $context['currency_code'],
            'operator_visibility' => (string) $data['operator_visibility'],
            'approval_visibility' => (string) $data['approval_visibility'],
            'is_active' => (bool) $data['is_active'],
        ];
    }

    private function assertCodeAvailable(
        string $table,
        int $companyId,
        int $storeId,
        string $code,
        ?int $ignoreId = null,
    ): void
    {
        $query = DB::table($table)
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('code', $code);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => [__('treasury.errors.duplicate_code')],
            ]);
        }
    }

    /** @return array{0:string,1:string} */
    private function referenceParts(string $reference): array
    {
        $reference = trim($reference);
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '';
        if (strlen($normalized) < 4) {
            throw ValidationException::withMessages([
                'account_reference' => [__('treasury.errors.reference_too_short')],
            ]);
        }

        return [$reference, substr($normalized, -4)];
    }

    private function normalizedCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
