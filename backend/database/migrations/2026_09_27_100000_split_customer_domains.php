<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('b2b_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_customer_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('b2c_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_customer_id')->nullable();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['legacy_customer_id', 'store_id'], 'b2c_customer_legacy_store_unique');
            $table->unique(['user_id', 'store_id'], 'b2c_customer_user_store_unique');
            $table->index(['store_id', 'email'], 'b2c_customer_store_email_index');
            $table->index(['store_id', 'phone'], 'b2c_customer_store_phone_index');
        });

        Schema::create('customer_domain_migration_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_customer_id')->nullable()->index();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason', 120);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'reason'],
                'customer_domain_migration_issue_unique',
            );
        });

        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->foreignId('b2b_customer_id')
                ->nullable()
                ->after('customer_id')
                ->unique()
                ->constrained('b2b_customers')
                ->nullOnDelete();
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->foreignId('b2b_customer_id')->nullable()->after('customer_id')->constrained('b2b_customers')->nullOnDelete();
            $table->foreignId('b2c_customer_id')->nullable()->after('b2b_customer_id')->constrained('b2c_customers')->nullOnDelete();
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('b2b_customer_id')->nullable()->after('customer_id')->constrained('b2b_customers')->nullOnDelete();
            $table->foreignId('b2c_customer_id')->nullable()->after('b2b_customer_id')->constrained('b2c_customers')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('b2b_customer_id')->nullable()->after('customer_id')->constrained('b2b_customers')->nullOnDelete();
            $table->foreignId('b2c_customer_id')->nullable()->after('b2b_customer_id')->constrained('b2c_customers')->nullOnDelete();
            $table->index(['store_id', 'b2c_customer_id'], 'orders_store_b2c_customer_index');
            $table->index(['b2b_customer_id', 'channel'], 'orders_b2b_customer_channel_index');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('b2b_customer_id')->nullable()->after('customer_id')->constrained('b2b_customers')->nullOnDelete();
            $table->foreignId('b2c_customer_id')->nullable()->after('b2b_customer_id')->constrained('b2c_customers')->nullOnDelete();
        });

        if (Schema::hasTable('customer_favorites')) {
            Schema::table('customer_favorites', function (Blueprint $table): void {
                $table->foreignId('b2c_customer_id')->nullable()->after('customer_id')->constrained('b2c_customers')->cascadeOnDelete();
                $table->unique(['b2c_customer_id', 'product_id'], 'customer_favorites_b2c_product_unique');
            });
        }

        $this->backfillCustomers();
        $this->backfillReferences();
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_favorites') && Schema::hasColumn('customer_favorites', 'b2c_customer_id')) {
            Schema::table('customer_favorites', function (Blueprint $table): void {
                $table->dropUnique('customer_favorites_b2c_product_unique');
                $table->dropConstrainedForeignId('b2c_customer_id');
            });
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2c_customer_id');
            $table->dropConstrainedForeignId('b2b_customer_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_store_b2c_customer_index');
            $table->dropIndex('orders_b2b_customer_channel_index');
            $table->dropConstrainedForeignId('b2c_customer_id');
            $table->dropConstrainedForeignId('b2b_customer_id');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2c_customer_id');
            $table->dropConstrainedForeignId('b2b_customer_id');
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2c_customer_id');
            $table->dropConstrainedForeignId('b2b_customer_id');
        });

        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2b_customer_id');
        });

        Schema::dropIfExists('customer_domain_migration_issues');
        Schema::dropIfExists('b2c_customers');
        Schema::dropIfExists('b2b_customers');
    }

    private function backfillCustomers(): void
    {
        DB::table('customers')
            ->whereRaw('LOWER(type) = ?', ['b2b'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $legacy) {
                    DB::table('b2b_customers')->updateOrInsert(
                        ['legacy_customer_id' => (int) $legacy->id],
                        [
                            'user_id' => $legacy->user_id,
                            'name' => (string) $legacy->name,
                            'phone' => $legacy->phone,
                            'email' => $legacy->email,
                            'created_at' => $legacy->created_at ?? now(),
                            'updated_at' => $legacy->updated_at ?? now(),
                        ],
                    );
                }
            });

        $pairs = DB::table('orders')
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->whereNotNull('customer_id')
            ->select(['customer_id', 'store_id'])
            ->distinct()
            ->union(
                DB::table('carts')
                    ->whereRaw('LOWER(channel) = ?', ['b2c'])
                    ->whereNotNull('customer_id')
                    ->select(['customer_id', 'store_id'])
                    ->distinct(),
            )
            ->get();

        $resolvedLegacyIds = [];

        foreach ($pairs as $pair) {
            $legacy = DB::table('customers')->where('id', $pair->customer_id)->first();
            if ($legacy === null || strtolower((string) $legacy->type) !== 'b2c') {
                $this->recordIssue(
                    $pair->customer_id === null ? null : (int) $pair->customer_id,
                    'customer',
                    $pair->customer_id === null ? null : (int) $pair->customer_id,
                    'b2c_reference_customer_domain_mismatch',
                    ['store_id' => (int) $pair->store_id],
                );
                continue;
            }

            if (! $this->isStoreChannel((int) $pair->store_id, 'B2C')) {
                $this->recordIssue(
                    (int) $legacy->id,
                    'customer',
                    (int) $legacy->id,
                    'b2c_reference_store_channel_mismatch',
                    ['store_id' => (int) $pair->store_id],
                );
                continue;
            }

            $this->upsertB2cCustomer($legacy, (int) $pair->store_id);
            $resolvedLegacyIds[(int) $legacy->id] = true;
        }

        $b2cStoreIds = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        DB::table('customers')
            ->whereRaw('LOWER(type) = ?', ['b2c'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$resolvedLegacyIds, $b2cStoreIds): void {
                foreach ($rows as $legacy) {
                    if (isset($resolvedLegacyIds[(int) $legacy->id])) {
                        continue;
                    }

                    if (count($b2cStoreIds) === 1) {
                        $this->upsertB2cCustomer($legacy, $b2cStoreIds[0]);
                        continue;
                    }

                    $this->recordIssue(
                        (int) $legacy->id,
                        'customer',
                        (int) $legacy->id,
                        'b2c_store_unresolved',
                        ['candidate_b2c_store_count' => count($b2cStoreIds)],
                    );
                }
            });
    }

    private function backfillReferences(): void
    {
        DB::table('b2b_accounts')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $target = $this->b2bCustomerId((int) $row->customer_id);
                if ($target === null) {
                    $this->recordIssue((int) $row->customer_id, 'b2b_account', (int) $row->id, 'b2b_customer_unresolved');
                    continue;
                }
                DB::table('b2b_accounts')->where('id', $row->id)->update(['b2b_customer_id' => $target]);
            }
        });

        DB::table('orders')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $channel = strtolower((string) $row->channel);
                if ($channel === 'b2b') {
                    $target = $this->b2bCustomerId((int) $row->customer_id);
                    if ($target !== null) {
                        DB::table('orders')->where('id', $row->id)->update(['b2b_customer_id' => $target]);
                    } else {
                        $this->recordIssue((int) $row->customer_id, 'order', (int) $row->id, 'b2b_customer_unresolved');
                    }
                    continue;
                }

                if ($channel === 'b2c') {
                    $target = $this->b2cCustomerId((int) $row->customer_id, (int) $row->store_id);
                    if ($target !== null) {
                        DB::table('orders')->where('id', $row->id)->update(['b2c_customer_id' => $target]);
                    } else {
                        $this->recordIssue(
                            (int) $row->customer_id,
                            'order',
                            (int) $row->id,
                            'b2c_customer_unresolved',
                            ['store_id' => (int) $row->store_id],
                        );
                    }
                }
            }
        });

        DB::table('carts')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->customer_id === null) {
                    continue;
                }

                $channel = strtolower((string) $row->channel);
                if ($channel === 'b2b') {
                    $target = $this->b2bCustomerId((int) $row->customer_id);
                    if ($target !== null) {
                        DB::table('carts')->where('id', $row->id)->update(['b2b_customer_id' => $target]);
                    } else {
                        $this->recordIssue((int) $row->customer_id, 'cart', (int) $row->id, 'b2b_customer_unresolved');
                    }
                    continue;
                }

                if ($channel === 'b2c') {
                    $target = $this->b2cCustomerId((int) $row->customer_id, (int) $row->store_id);
                    if ($target !== null) {
                        DB::table('carts')->where('id', $row->id)->update(['b2c_customer_id' => $target]);
                    } else {
                        $this->recordIssue(
                            (int) $row->customer_id,
                            'cart',
                            (int) $row->id,
                            'b2c_customer_unresolved',
                            ['store_id' => (int) $row->store_id],
                        );
                    }
                }
            }
        });

        DB::table('invoices')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->order_id !== null) {
                    $order = DB::table('orders')->where('id', $row->order_id)->first(['b2b_customer_id', 'b2c_customer_id']);
                    if ($order !== null && ($order->b2b_customer_id !== null || $order->b2c_customer_id !== null)) {
                        DB::table('invoices')->where('id', $row->id)->update([
                            'b2b_customer_id' => $order->b2b_customer_id,
                            'b2c_customer_id' => $order->b2c_customer_id,
                        ]);
                        continue;
                    }
                }

                $b2b = $this->b2bCustomerId((int) $row->customer_id);
                if ($b2b !== null) {
                    DB::table('invoices')->where('id', $row->id)->update(['b2b_customer_id' => $b2b]);
                    continue;
                }

                $b2c = DB::table('b2c_customers')->where('legacy_customer_id', $row->customer_id)->pluck('id');
                if ($b2c->count() === 1) {
                    DB::table('invoices')->where('id', $row->id)->update(['b2c_customer_id' => (int) $b2c->first()]);
                } else {
                    $this->recordIssue(
                        (int) $row->customer_id,
                        'invoice',
                        (int) $row->id,
                        $b2c->isEmpty() ? 'customer_domain_unresolved' : 'b2c_store_ambiguous',
                    );
                }
            }
        });

        $this->backfillProfileReferences('addresses');
        if (Schema::hasTable('customer_favorites')) {
            $this->backfillProfileReferences('customer_favorites');
        }
    }

    private function backfillProfileReferences(string $table): void
    {
        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                $legacyId = (int) $row->customer_id;
                $b2b = $this->b2bCustomerId($legacyId);
                if ($b2b !== null) {
                    if ($table === 'addresses') {
                        DB::table($table)->where('id', $row->id)->update(['b2b_customer_id' => $b2b]);
                    }
                    continue;
                }

                $b2c = DB::table('b2c_customers')->where('legacy_customer_id', $legacyId)->pluck('id');
                if ($b2c->count() === 1) {
                    DB::table($table)->where('id', $row->id)->update(['b2c_customer_id' => (int) $b2c->first()]);
                    continue;
                }

                $this->recordIssue(
                    $legacyId,
                    $table === 'addresses' ? 'address' : 'favorite',
                    (int) $row->id,
                    $b2c->isEmpty() ? 'b2c_customer_unresolved' : 'b2c_store_ambiguous',
                );
            }
        });
    }

    private function upsertB2cCustomer(object $legacy, int $storeId): int
    {
        DB::table('b2c_customers')->updateOrInsert(
            ['legacy_customer_id' => (int) $legacy->id, 'store_id' => $storeId],
            [
                'user_id' => $legacy->user_id,
                'name' => (string) $legacy->name,
                'phone' => $legacy->phone,
                'email' => $legacy->email,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => $legacy->updated_at ?? now(),
            ],
        );

        return (int) DB::table('b2c_customers')
            ->where('legacy_customer_id', $legacy->id)
            ->where('store_id', $storeId)
            ->value('id');
    }

    private function b2bCustomerId(int $legacyCustomerId): ?int
    {
        $id = DB::table('b2b_customers')->where('legacy_customer_id', $legacyCustomerId)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function b2cCustomerId(int $legacyCustomerId, int $storeId): ?int
    {
        $id = DB::table('b2c_customers')
            ->where('legacy_customer_id', $legacyCustomerId)
            ->where('store_id', $storeId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function isStoreChannel(int $storeId, string $channel): bool
    {
        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('store_types.code', strtoupper($channel))
            ->exists();
    }

    private function recordIssue(
        ?int $legacyCustomerId,
        string $sourceType,
        ?int $sourceId,
        string $reason,
        array $details = [],
    ): void {
        DB::table('customer_domain_migration_issues')->updateOrInsert(
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
            ],
            [
                'legacy_customer_id' => $legacyCustomerId,
                'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
