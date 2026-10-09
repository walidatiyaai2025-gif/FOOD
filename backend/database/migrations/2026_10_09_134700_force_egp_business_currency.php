<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $currencyTables = [
            'orders',
            'order_items',
            'invoices',
            'invoice_items',
            'payments',
            'refunds',
            'customer_account_ledger_entries',
            'collection_transactions',
            'collection_allocations',
            'custody_ledger_entries',
            'remittances',
            'remittance_allocations',
        ];

        foreach ($currencyTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency')) {
                DB::table($table)
                    ->where(function ($query): void {
                        $query->whereNull('currency')
                            ->orWhere('currency', '<>', 'EGP');
                    })
                    ->update(['currency' => 'EGP']);
            }
        }

        $this->normalizeCollectionAccounts();

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', ['currency', 'default_currency', 'store.currency', 'checkout.currency'])
                ->update([
                    'value' => json_encode('EGP'),
                    'updated_at' => now(),
                ]);
        }
    }

    private function normalizeCollectionAccounts(): void
    {
        if (! Schema::hasTable('collection_accounts') || ! Schema::hasColumn('collection_accounts', 'currency')) {
            return;
        }

        $accounts = DB::table('collection_accounts')
            ->where('currency', '<>', 'EGP')
            ->orderBy('id')
            ->get(['id', 'actor_type', 'actor_id', 'store_id']);

        foreach ($accounts as $account) {
            $existing = DB::table('collection_accounts')
                ->where('currency', 'EGP')
                ->where('actor_type', $account->actor_type)
                ->where('actor_id', $account->actor_id)
                ->when(
                    $account->store_id === null,
                    fn ($query) => $query->whereNull('store_id'),
                    fn ($query) => $query->where('store_id', $account->store_id),
                )
                ->value('id');

            if ($existing !== null) {
                foreach (['collection_transactions', 'custody_ledger_entries', 'remittances'] as $childTable) {
                    if (Schema::hasTable($childTable) && Schema::hasColumn($childTable, 'collection_account_id')) {
                        DB::table($childTable)
                            ->where('collection_account_id', $account->id)
                            ->update(['collection_account_id' => $existing]);
                    }
                }

                DB::table('collection_accounts')->where('id', $account->id)->delete();

                continue;
            }

            DB::table('collection_accounts')
                ->where('id', $account->id)
                ->update([
                    'currency' => 'EGP',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: financial records must not be relabelled back to a foreign currency.
    }
};
