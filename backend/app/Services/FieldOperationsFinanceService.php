<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class FieldOperationsFinanceService
{
    /**
     * @param list<int> $storeIds
     * @param array{ops_tab?:string,ops_q?:?string,ops_status?:?string,ops_per_page?:int} $filters
     * @return array<string,mixed>
     */
    public function viewModel(array $storeIds, array $filters): array
    {
        $tab = in_array(($filters['ops_tab'] ?? 'wallets'), ['wallets', 'collections', 'remittances', 'reconciliation'], true)
            ? (string) ($filters['ops_tab'] ?? 'wallets')
            : 'wallets';
        $q = trim((string) ($filters['ops_q'] ?? ''));
        $status = trim((string) ($filters['ops_status'] ?? ''));
        $perPage = max(10, min(100, (int) ($filters['ops_per_page'] ?? 25)));

        $paginator = match ($tab) {
            'collections' => $this->collections($storeIds, $q, $status, $perPage),
            'remittances' => $this->remittances($storeIds, $q, $status, $perPage, false),
            'reconciliation' => $this->remittances($storeIds, $q, $status, $perPage, true),
            default => $this->wallets($storeIds, $q, $status, $perPage),
        };

        return [
            'tab' => $tab,
            'q' => $q,
            'status' => $status,
            'per_page' => $perPage,
            'rows' => collect($paginator->items())->map(fn (object $row): array => (array) $row)->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'status_options' => $this->statusOptions($tab),
        ];
    }

    /** @param list<int> $storeIds */
    private function wallets(array $storeIds, string $q, string $status, int $perPage): LengthAwarePaginator
    {
        $custody = DB::table('custody_ledger_entries')
            ->select('collection_account_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as custody_balance')
            ->groupBy('collection_account_id');

        $pending = DB::table('remittances')
            ->where('status', 'pending')
            ->select('collection_account_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as pending_remittance')
            ->groupBy('collection_account_id');

        $availableExpression = 'COALESCE(custody_totals.custody_balance, 0) - COALESCE(pending_totals.pending_remittance, 0)';

        return DB::table('collection_accounts')
            ->leftJoin('stores', 'stores.id', '=', 'collection_accounts.store_id')
            ->leftJoinSub($custody, 'custody_totals', fn ($join) => $join->on('custody_totals.collection_account_id', '=', 'collection_accounts.id'))
            ->leftJoinSub($pending, 'pending_totals', fn ($join) => $join->on('pending_totals.collection_account_id', '=', 'collection_accounts.id'))
            ->whereIn('collection_accounts.store_id', $storeIds)
            ->when($status !== '', fn ($query) => $query->where('collection_accounts.status', $status))
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($scope) use ($q): void {
                    $scope->where('collection_accounts.actor_type', 'like', '%'.$q.'%')
                        ->orWhere('collection_accounts.currency', 'like', '%'.$q.'%')
                        ->orWhere('collection_accounts.status', 'like', '%'.$q.'%')
                        ->orWhere('stores.name', 'like', '%'.$q.'%');
                    if (ctype_digit($q)) {
                        $scope->orWhere('collection_accounts.actor_id', (int) $q)
                            ->orWhere('collection_accounts.id', (int) $q);
                    }
                });
            })
            ->orderBy('collection_accounts.currency')
            ->orderByDesc('collection_accounts.id')
            ->select([
                'collection_accounts.id',
                'collection_accounts.actor_type',
                'collection_accounts.actor_id',
                'collection_accounts.store_id',
                'stores.name as store_name',
                'collection_accounts.currency',
                'collection_accounts.status',
                'collection_accounts.custody_limit',
                DB::raw('COALESCE(custody_totals.custody_balance, 0) as custody_balance'),
                DB::raw('COALESCE(pending_totals.pending_remittance, 0) as pending_remittance'),
                DB::raw("CASE WHEN {$availableExpression} > 0 THEN {$availableExpression} ELSE 0 END as available_to_remit"),
            ])
            ->paginate($perPage, ['*'], 'ops_page');
    }

    /** @param list<int> $storeIds */
    private function collections(array $storeIds, string $q, string $status, int $perPage): LengthAwarePaginator
    {
        return DB::table('collection_transactions')
            ->join('collection_accounts', 'collection_accounts.id', '=', 'collection_transactions.collection_account_id')
            ->leftJoin('stores', 'stores.id', '=', 'collection_accounts.store_id')
            ->leftJoin('payments', 'payments.id', '=', 'collection_transactions.payment_id')
            ->whereIn('collection_accounts.store_id', $storeIds)
            ->when($status !== '', fn ($query) => $query->where('collection_transactions.status', $status))
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($scope) use ($q): void {
                    $scope->where('collection_transactions.idempotency_key', 'like', '%'.$q.'%')
                        ->orWhere('collection_transactions.source', 'like', '%'.$q.'%')
                        ->orWhere('collection_transactions.currency', 'like', '%'.$q.'%')
                        ->orWhere('collection_accounts.actor_type', 'like', '%'.$q.'%')
                        ->orWhere('stores.name', 'like', '%'.$q.'%')
                        ->orWhere('payments.provider_reference', 'like', '%'.$q.'%');
                    if (ctype_digit($q)) {
                        $scope->orWhere('collection_transactions.id', (int) $q)
                            ->orWhere('collection_accounts.actor_id', (int) $q);
                    }
                });
            })
            ->orderByDesc('collection_transactions.id')
            ->select([
                'collection_transactions.id',
                'collection_transactions.collection_account_id',
                'collection_transactions.payment_id',
                'collection_transactions.idempotency_key',
                'collection_transactions.type',
                'collection_transactions.status',
                'collection_transactions.amount',
                'collection_transactions.currency',
                'collection_transactions.source',
                'collection_transactions.created_at',
                'collection_accounts.actor_type',
                'collection_accounts.actor_id',
                'collection_accounts.store_id',
                'stores.name as store_name',
                'payments.provider_reference',
            ])
            ->paginate($perPage, ['*'], 'ops_page');
    }

    /** @param list<int> $storeIds */
    private function remittances(
        array $storeIds,
        string $q,
        string $status,
        int $perPage,
        bool $reconciliation,
    ): LengthAwarePaginator {
        $custody = DB::table('custody_ledger_entries')
            ->select('collection_account_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as custody_balance')
            ->groupBy('collection_account_id');

        return DB::table('remittances')
            ->join('collection_accounts', 'collection_accounts.id', '=', 'remittances.collection_account_id')
            ->leftJoin('stores', 'stores.id', '=', 'collection_accounts.store_id')
            ->leftJoin('users as submitted_users', 'submitted_users.id', '=', 'remittances.submitted_by')
            ->leftJoin('users as reviewed_users', 'reviewed_users.id', '=', 'remittances.reviewed_by')
            ->leftJoinSub($custody, 'custody_totals', fn ($join) => $join->on('custody_totals.collection_account_id', '=', 'collection_accounts.id'))
            ->whereIn('collection_accounts.store_id', $storeIds)
            ->when($reconciliation && $status === '', fn ($query) => $query->whereIn('remittances.status', ['pending', 'approved', 'rejected', 'reconciled']))
            ->when($status !== '', fn ($query) => $query->where('remittances.status', $status))
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($scope) use ($q): void {
                    $scope->where('remittances.idempotency_key', 'like', '%'.$q.'%')
                        ->orWhere('remittances.reference', 'like', '%'.$q.'%')
                        ->orWhere('remittances.method', 'like', '%'.$q.'%')
                        ->orWhere('remittances.currency', 'like', '%'.$q.'%')
                        ->orWhere('collection_accounts.actor_type', 'like', '%'.$q.'%')
                        ->orWhere('stores.name', 'like', '%'.$q.'%');
                    if (ctype_digit($q)) {
                        $scope->orWhere('remittances.id', (int) $q)
                            ->orWhere('collection_accounts.actor_id', (int) $q);
                    }
                });
            })
            ->orderByRaw("CASE remittances.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 WHEN 'reconciled' THEN 3 ELSE 4 END")
            ->orderByDesc('remittances.id')
            ->select([
                'remittances.id',
                'remittances.collection_account_id',
                'remittances.idempotency_key',
                'remittances.amount',
                'remittances.currency',
                'remittances.method',
                'remittances.reference',
                'remittances.status',
                'remittances.note',
                'remittances.created_at',
                'remittances.reviewed_at',
                'collection_accounts.actor_type',
                'collection_accounts.actor_id',
                'collection_accounts.store_id',
                'stores.name as store_name',
                'submitted_users.name as submitted_by_name',
                'reviewed_users.name as reviewed_by_name',
                DB::raw('COALESCE(custody_totals.custody_balance, 0) as custody_balance'),
                DB::raw("CASE WHEN remittances.status = 'pending' AND remittances.amount > COALESCE(custody_totals.custody_balance, 0) THEN 1 ELSE 0 END as has_exception"),
            ])
            ->paginate($perPage, ['*'], 'ops_page');
    }

    /** @return list<string> */
    private function statusOptions(string $tab): array
    {
        return match ($tab) {
            'wallets' => ['active', 'suspended', 'closed'],
            'collections' => ['posted', 'reversed', 'failed'],
            'remittances', 'reconciliation' => ['pending', 'approved', 'rejected', 'reconciled'],
            default => [],
        };
    }
}
