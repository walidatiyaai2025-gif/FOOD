<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class B2bAccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)\n    {\n    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('b2b.accounts.manage');
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $accounts = B2bAccount::query()->with('customer.user')->orderBy('id')->paginate($perPage);

        return response()->json(['data' => collect($accounts->items())->map(fn (B2bAccount $a) => $this->resource($a))->all(), 'meta' => ['current_page' => $accounts->currentPage(), 'per_page' => $accounts->perPage(), 'total' => $accounts->total()]]);
    }

    public function store(Request $request): JsonResponse
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

        $account = DB::transaction(function () use ($data): B2bAccount {
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_active' => false]);
            $customer = Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email']]);
            return B2bAccount::query()->create(['customer_id' => $customer->id, 'company_name' => $data['company_name'], 'tax_number' => $data['tax_number'] ?? null, 'status' => 'pending']);
        });
        $account->load('customer.user');
        $this->audit->record('b2b.account.created', $request->user(), $account, null, ['status' => 'pending', 'company_name' => $account->company_name], $request);

        return response()->json(['data' => $this->resource($account)], 201);
    }

    public function updateStatus(Request $request, B2bAccount $account): JsonResponse
    {
        Gate::authorize('b2b.accounts.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'active', 'denied', 'suspended'])]]);
        $before = $account->status;
        $account->update(['status' => $data['status']]);
        $account->customer()->with('user')->first()?->user?->update(['is_active' => $data['status'] === 'active']);
        $this->audit->record('b2b.account.status_changed', $request->user(), $account, ['status' => $before], ['status' => $account->status], $request);
        $account->load('customer.user');

        return response()->json(['data' => $this->resource($account)]);
    }

    private function resource(B2bAccount $account): array
    {
        return ['id' => (int) $account->id, 'company_name' => $account->company_name, 'tax_number' => $account->tax_number, 'status' => $account->status, 'customer' => ['id' => (int) $account->customer->id, 'name' => $account->customer->name, 'email' => $account->customer->email, 'phone' => $account->customer->phone]];
    }
}
