<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\B2bCustomerService;
use App\Services\PlatformCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class B2bAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('b2b.accounts.manage');

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $accounts = B2bAccount::query()
            ->with('b2bCustomer.user')
            ->whereNotNull('b2b_customer_id')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($accounts->items())->map(fn (B2bAccount $account) => $this->resource($account))->all(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function store(
        Request $request,
        B2bCustomerService $customers,
        PlatformCustomerService $platformCustomers,
    ): JsonResponse
    {
        Gate::authorize('b2b.accounts.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
        ]);

        $account = DB::transaction(function () use ($data, $customers, $platformCustomers): B2bAccount {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => false,
            ]);

            $customer = $customers->create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
            ], $user);

            $platformCustomers->reconcileWholesaleCustomerIdentity(
                $user,
                $customer,
                'dashboard',
            );

            return B2bAccount::query()->create([
                'customer_id' => $customer->legacy_customer_id,
                'b2b_customer_id' => $customer->getKey(),
                'company_name' => $data['company_name'],
                'tax_number' => $data['tax_number'] ?? null,
                'status' => 'pending',
            ]);
        });

        $account->load('b2bCustomer.user');

        app(AuditLogger::class)->record(
            'b2b.account.created',
            $request->user(),
            $account,
            null,
            ['status' => 'pending', 'company_name' => $account->company_name],
            $request,
        );

        return response()->json(['data' => $this->resource($account)], 201);
    }

    public function updateStatus(Request $request, B2bAccount $account): JsonResponse
    {
        Gate::authorize('b2b.accounts.manage');
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'active', 'denied', 'suspended'])],
        ]);

        $retailStore = DB::table('retail_wholesale_accounts')
            ->join('stores', 'stores.id', '=', 'retail_wholesale_accounts.retail_store_id')
            ->where('retail_wholesale_accounts.b2b_customer_id', $account->b2b_customer_id)
            ->first(['stores.id', 'stores.is_active']);

        if ($retailStore !== null) {
            $expectedStatus = (bool) $retailStore->is_active ? 'active' : 'suspended';
            abort_unless(
                $data['status'] === $expectedStatus,
                422,
                'Retail-linked Wholesale account status is controlled by the Retail store status.',
            );
        }

        $before = $account->status;
        $customer = $account->b2bCustomer()->with('user')->first();
        abort_unless($customer instanceof B2bCustomer, 409, 'B2B customer domain mapping is incomplete.');

        DB::transaction(function () use ($account, $customer, $data): void {
            $account->update(['status' => $data['status']]);
            $customer->user?->update(['is_active' => $data['status'] === 'active']);
        });

        app(AuditLogger::class)->record(
            'b2b.account.status_changed',
            $request->user(),
            $account,
            ['status' => $before],
            ['status' => $account->status],
            $request,
        );

        $account->load('b2bCustomer.user');

        return response()->json(['data' => $this->resource($account)]);
    }

    private function resource(B2bAccount $account): array
    {
        $customer = $account->b2bCustomer;
        abort_unless($customer instanceof B2bCustomer, 409, 'B2B customer domain mapping is incomplete.');

        return [
            'id' => (int) $account->id,
            'company_name' => $account->company_name,
            'tax_number' => $account->tax_number,
            'status' => $account->status,
            'customer' => [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
        ];
    }
}
