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
                'password' => [__('account_deletion.identity_failed')],
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

        // Public self-deletion must not bypass managed RBAC offboarding.
        $operationalRoles = ['B2B_DRIVER', 'B2C_DRIVER', 'VAN_OPERATOR'];
        $hasManagedRole = $user->roles()
            ->whereNotIn('roles.code', $operationalRoles)
            ->exists();

        if ($user->isPlatformOwner() || $hasManagedRole || $user->storeRoleAssignments()->exists()) {
            throw ValidationException::withMessages([
                'confirmation' => [__('admin.security.errors.privileged_account_deletion')],
            ]);
        }

        $context = $this->retentionContext($user);
        // Driver and Van identities are operational even with a linked customer.
        $operationalIdentity = $context['driver_account'] || $user->roles()
            ->whereIn('roles.code', $operationalRoles)
            ->exists();

        $request = AccountDeletionRequest::query()->create([
            'user_id' => $user->getKey(),
            'app' => $operationalIdentity ? 'driver' : 'customer',
            'verification_method' => 'password',
            'status' => $operationalIdentity
                ? 'OPERATIONAL_REVIEW_REQUIRED'
                : ($context['active_orders'] > 0 || $context['financial_review_required'] || $context['outstanding_finance']
                    ? 'PENDING_RETENTION'
                    : 'PROCESSING'),
            'block_reason' => $operationalIdentity
                ? __('account_deletion.operational_review')
                : ($context['active_orders'] > 0
                    ? __('account_deletion.active_orders')
                    : ($context['financial_review_required'] || $context['outstanding_finance']
                        ? __('account_deletion.financial_review')
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

        // IDs from these tables are separate namespaces. In particular an
        // unrelated legacy customer ID may equal a B2B/B2C domain ID.
        $identities = [];
        foreach (['customers', 'b2b_customers', 'b2c_customers'] as $table) {
            $identities[$table] = Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')
                ? DB::table($table)->where('user_id', $userId)->pluck('id')->all()
                : [];
        }

        $legacyIds = $identities['customers'];
        $b2bIds = $identities['b2b_customers'];
        $b2cIds = $identities['b2c_customers'];

        $activeOrders = 0;
        if (Schema::hasTable('orders')) {
            $orders = DB::table('orders')->where(function ($scope) use ($legacyIds, $b2bIds, $b2cIds, $userId): void {
                $scope->whereRaw('1 = 0');
                foreach ([
                    'customer_id' => $legacyIds,
                    'b2b_customer_id' => $b2bIds,
                    'b2c_customer_id' => $b2cIds,
                ] as $column => $ids) {
                    if ($ids !== [] && Schema::hasColumn('orders', $column)) {
                        $scope->orWhereIn($column, $ids);
                    }
                }
                if (Schema::hasColumn('orders', 'user_id')) {
                    $scope->orWhere('user_id', $userId);
                }
            });

            if (Schema::hasColumn('orders', 'status')) {
                $orders->whereNotIn('status', ['delivered', 'cancelled', 'canceled', 'rejected', 'failed', 'refunded']);
            }
            $activeOrders = $orders->count();
        }

        $linkedAccount = false;
        $outstandingFinance = false;
        if (Schema::hasTable('b2b_accounts')) {
            // Never use the first column that happens to exist: both links
            // can coexist after the domain split and have different values.
            $accounts = DB::table('b2b_accounts')->where(function ($scope) use ($legacyIds, $b2bIds): void {
                $scope->whereRaw('1 = 0');
                if ($legacyIds !== [] && Schema::hasColumn('b2b_accounts', 'customer_id')) {
                    $scope->orWhereIn('customer_id', $legacyIds);
                }
                if ($b2bIds !== [] && Schema::hasColumn('b2b_accounts', 'b2b_customer_id')) {
                    $scope->orWhereIn('b2b_customer_id', $b2bIds);
                }
            });
            $linkedAccount = (clone $accounts)->exists();

            // Historical installations may have a stored balance. Modern B2B
            // finance derives balances from invoices, payments and ledger
            // entries instead, so absence of this column is NOT proof of zero.
            foreach (['balance', 'outstanding_balance', 'outstanding_receivable'] as $column) {
                if (Schema::hasColumn('b2b_accounts', $column)) {
                    $outstandingFinance = (clone $accounts)->where($column, '>', 0.0001)->exists();
                    if ($outstandingFinance) {
                        break;
                    }
                }
            }
        }

        $linkedInvoices = false;
        if (Schema::hasTable('invoices')) {
            $linkedInvoices = DB::table('invoices')->where(function ($scope) use ($legacyIds, $b2bIds, $b2cIds): void {
                $scope->whereRaw('1 = 0');
                foreach ([
                    'customer_id' => $legacyIds,
                    'b2b_customer_id' => $b2bIds,
                    'b2c_customer_id' => $b2cIds,
                ] as $column => $ids) {
                    if ($ids !== [] && Schema::hasColumn('invoices', $column)) {
                        $scope->orWhereIn($column, $ids);
                    }
                }
            })->exists();
        }

        $linkedLedger = $b2bIds !== []
            && Schema::hasTable('customer_account_ledger_entries')
            && Schema::hasColumn('customer_account_ledger_entries', 'b2b_customer_id')
            && DB::table('customer_account_ledger_entries')->whereIn('b2b_customer_id', $b2bIds)->exists();

        $linkedEnterpriseAr = $legacyIds !== []
            && Schema::hasTable('enterprise_accounts_receivable_documents')
            && Schema::hasColumn('enterprise_accounts_receivable_documents', 'customer_id')
            && DB::table('enterprise_accounts_receivable_documents')->whereIn('customer_id', $legacyIds)->exists();

        $legacyB2bProfile = $legacyIds !== []
            && Schema::hasColumn('customers', 'type')
            && DB::table('customers')->whereIn('id', $legacyIds)->where('type', 'b2b')->exists();

        // Manual retention review is required for contractual B2B accounts and
        // any traceable finance record, even when no physical balance column
        // exists or an invoice is already settled. Never infer safe automatic
        // anonymization from a missing/zero denormalized balance.
        $financialReviewRequired = $b2bIds !== []
            || $legacyB2bProfile
            || $linkedAccount
            || $linkedInvoices
            || $linkedLedger
            || $linkedEnterpriseAr;

        $driverAccount = Schema::hasTable('drivers')
            && Schema::hasColumn('drivers', 'user_id')
            && DB::table('drivers')->where('user_id', $userId)->exists();

        return [
            'customer_account' => $legacyIds !== [] || $b2bIds !== [] || $b2cIds !== [],
            'driver_account' => $driverAccount,
            'active_orders' => $activeOrders,
            'outstanding_finance' => $outstandingFinance,
            'financial_review_required' => $financialReviewRequired,
            'financial_records_retained' => true,
            'order_records_retained' => true,
        ];
    }
}
