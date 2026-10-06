<?php

namespace App\Services;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountDeletionService
{
    public function request(User $user, string $password): AccountDeletionRequest
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Identity verification failed.'],
            ]);
        }

        $existing = AccountDeletionRequest::query()
            ->where('user_id', $user->getKey())
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
            ->latest('id')
            ->first();

        if ($existing instanceof AccountDeletionRequest) {
            return $existing;
        }

        $context = $this->retentionContext($user);
        $driverOnly = $context['driver_account'] && ! $context['customer_account'];

        $request = AccountDeletionRequest::query()->create([
            'user_id' => $user->getKey(),
            'app' => $driverOnly ? 'driver' : 'customer',
            'verification_method' => 'password',
            'status' => $driverOnly
                ? 'OPERATIONAL_REVIEW_REQUIRED'
                : ($context['active_orders'] > 0 || $context['outstanding_finance']
                    ? 'PENDING_RETENTION'
                    : 'PROCESSING'),
            'block_reason' => $driverOnly
                ? 'Driver/Van operational identity requires managed deactivation and retention review.'
                : ($context['active_orders'] > 0
                    ? 'Active orders must reach a terminal state before account anonymization.'
                    : ($context['outstanding_finance']
                        ? 'Outstanding financial obligations require retention review.'
                        : null)),
            'retention_context' => $context,
        ]);

        if ($request->status === 'PROCESSING') {
            $this->completeCustomerDeletion($request, $user);
        }

        return $request->refresh();
    }

    public function status(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->first();
    }

    private function completeCustomerDeletion(AccountDeletionRequest $request, User $user): void
    {
        DB::transaction(function () use ($request, $user): void {
            $userId = (int) $user->getKey();
            $deletedEmail = sprintf('deleted+%d+%s@privacy.invalid', $userId, substr(hash('sha256', (string) $user->email), 0, 12));
            $deletedName = 'Deleted Customer '.$userId;

            foreach (['customers', 'b2b_customers', 'b2c_customers', 'platform_customers'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                    continue;
                }

                $values = [];
                if (Schema::hasColumn($table, 'name')) {
                    $values['name'] = $deletedName;
                }
                if (Schema::hasColumn($table, 'email')) {
                    $values['email'] = $deletedEmail;
                }
                if (Schema::hasColumn($table, 'phone')) {
                    $values['phone'] = null;
                }

                if ($values !== []) {
                    DB::table($table)->where('user_id', $userId)->update($values);
                }
            }

            if (Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'user_id')) {
                DB::table('addresses')->where('user_id', $userId)->delete();
            }

            $user->tokens()->delete();
            $user->forceFill([
                'name' => $deletedName,
                'username' => 'deleted_'.$userId,
                'email' => $deletedEmail,
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Customer self-service account deletion',
            ])->save();

            $request->forceFill([
                'status' => 'COMPLETED',
                'anonymized_at' => now(),
                'completed_at' => now(),
                'block_reason' => null,
            ])->save();
        });
    }

    private function retentionContext(User $user): array
    {
        $userId = (int) $user->getKey();
        $customerIds = collect();

        foreach (['customers', 'b2b_customers', 'b2c_customers'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                $ids = DB::table($table)->where('user_id', $userId)->pluck('id');
                $customerIds = $customerIds->merge($ids);
            }
        }

        $activeOrders = 0;
        if (Schema::hasTable('orders')) {
            $query = DB::table('orders');
            $scope = $query->whereRaw('1 = 0');
            foreach (['customer_id', 'b2b_customer_id', 'b2c_customer_id'] as $column) {
                if (Schema::hasColumn('orders', $column) && $customerIds->isNotEmpty()) {
                    $scope->orWhereIn($column, $customerIds->all());
                }
            }
            if (Schema::hasColumn('orders', 'user_id')) {
                $scope->orWhere('user_id', $userId);
            }

            if (Schema::hasColumn('orders', 'status')) {
                $scope->whereNotIn('status', ['delivered', 'cancelled', 'canceled', 'rejected', 'failed', 'refunded']);
            }
            $activeOrders = $scope->count();
        }

        $outstandingFinance = false;
        if (Schema::hasTable('b2b_accounts') && $customerIds->isNotEmpty()) {
            $accountQuery = DB::table('b2b_accounts');
            foreach (['customer_id', 'b2b_customer_id'] as $column) {
                if (Schema::hasColumn('b2b_accounts', $column)) {
                    $accountQuery->whereIn($column, $customerIds->all());
                    break;
                }
            }
            foreach (['balance', 'outstanding_balance', 'outstanding_receivable'] as $column) {
                if (Schema::hasColumn('b2b_accounts', $column)) {
                    $outstandingFinance = (float) $accountQuery->sum($column) > 0.0001;
                    break;
                }
            }
        }

        $driverAccount = Schema::hasTable('drivers')
            && Schema::hasColumn('drivers', 'user_id')
            && DB::table('drivers')->where('user_id', $userId)->exists();

        return [
            'customer_account' => $customerIds->isNotEmpty(),
            'driver_account' => $driverAccount,
            'active_orders' => $activeOrders,
            'outstanding_finance' => $outstandingFinance,
            'financial_records_retained' => true,
            'order_records_retained' => true,
        ];
    }
}
