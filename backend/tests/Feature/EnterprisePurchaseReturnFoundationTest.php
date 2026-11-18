<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\PurchaseReturnManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\EnterpriseGoodsReceipt;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterprisePurchaseReturnFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_purchase_return_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PRTN-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(PurchaseReturnManager::class)->paginate($actor, $context);
            $this->fail('Disabled Procurement must reject purchase return reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_purchase_return'),
            ),
        );
    }

    public function test_posted_purchase_return_reverses_inventory_once_and_is_retry_safe(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-A');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-A-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-A-SUP', 'PRTN-A-SKU', 5);
        $receiptLine = $receipt->lines()->firstOrFail();

        $manager = app(PurchaseReturnManager::class);
        $return = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
            ]],
        ]);

        $posted = $manager->post($actor, $context, $return->id);
        $retry = $manager->post($actor, $context, $return->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->posted_at?->toISOString(), $retry->posted_at?->toISOString());
        $this->assertSame($receipt->receipt_number, $posted->source_receipt_number);
        $this->assertSame('5.000', $posted->lines[0]->received_quantity);
        $this->assertSame('2.000', $posted->lines[0]->returned_quantity);

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(3.0, (float) $inventory->quantity);
        // This return is unattributed, never silently FOODEX/supplier-owned.
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 3,
            'reserved_quantity' => 0,
            'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->count());
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_return')
                ->where('quantity', -2)
                ->where('reference_type', 'enterprise_purchase_return_line')
                ->where('reference_id', $posted->lines[0]->id)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_return.posted')
                ->where('auditable_id', $return->id)
                ->count(),
        );
    }

    /**
     * P110: supplier identity on a posted receipt does not certify legal title.
     * A persisted draft must not return zero/negative stock or forge its source
     * after the original positive request validation has already completed.
     */
    public function test_p110_fractional_supplier_return_replay_and_tampered_draft_fail_without_owner_or_stock_writes(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-P110-MILLI');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-P110-MILLI-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PRTN-P110-MILLI-SUP', 'PRTN-P110-MILLI-SKU', 1.500,
        );
        $source = $receipt->lines()->firstOrFail();
        $manager = app(PurchaseReturnManager::class);

        $first = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'owner_key' => 'FOODEX',
            'supplier_id' => 999999,
            'lines' => [[
                'goods_receipt_line_id' => $source->id,
                'quantity' => '0.625',
                'owner_key' => 'SUPPLIER:'.$receipt->supplier_id,
                'unit_price' => 0,
            ]],
        ]);
        $posted = $manager->post($actor, $context, $first->id);
        $replayed = $manager->post($actor, $context, $first->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($posted->posted_at?->toISOString(), $replayed->posted_at?->toISOString());
        $this->assertSame((int) $receipt->supplier_id, (int) $posted->supplier_id);
        $this->assertSame('0.625', $posted->lines[0]->returned_quantity);
        $this->assertSame((string) $source->unit_price, (string) $posted->lines[0]->unit_price);

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $this->assertSame('0.875', number_format((float) $inventory->quantity, 3, '.', ''));
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'certified_by' => null,
        ]);
        $this->assertSame('0.875', number_format((float) DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->value('quantity'), 3, '.', ''));
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->count());
        $returnMovement = DB::table('stock_movements')
            ->where('inventory_id', $inventory->id)
            ->where('type', 'purchase_return')
            ->where('reference_type', 'enterprise_purchase_return_line')
            ->where('reference_id', $posted->lines[0]->id)
            ->firstOrFail();
        $returnEvent = DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $returnMovement->id)->firstOrFail();
        $this->assertSame('UNKNOWN', $returnEvent->owner_snapshot_state);
        $this->assertSame('0.875', number_format((float) $returnEvent->owner_quantity, 3, '.', ''));
        $this->assertSame('0.875', number_format((float) $returnEvent->observed_inventory_quantity, 3, '.', ''));
        $this->assertSame('-0.625', number_format((float) $returnEvent->movement_quantity, 3, '.', ''));
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $returnMovement->id)->count(), 'Return replay must not append a duplicate event.');

        $draft = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [['goods_receipt_line_id' => $source->id, 'quantity' => '0.250']],
        ]);
        $draftLine = $draft->lines()->firstOrFail();

        foreach ([
            'returned_quantity' => '-0.250',
            'item_code' => 'FORGED-SKU',
            'unit' => 'case',
            'received_quantity' => '9.000',
            'unit_price' => '99.000',
        ] as $column => $forged) {
            DB::table('enterprise_purchase_return_lines')
                ->where('id', $draftLine->id)->update([$column => $forged]);
            try {
                $manager->post($actor, $context, $draft->id);
                $this->fail('Persisted supplier return source must fail closed for '.$column);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lines', $exception->errors());
            }
            DB::table('enterprise_purchase_return_lines')
                ->where('id', $draftLine->id)->update([$column => $draftLine->{$column}]);
        }

        foreach (['supplier_code' => 'FOREIGN-SUP', 'currency_code' => 'USD'] as $column => $forged) {
            DB::table('enterprise_purchase_returns')
                ->where('id', $draft->id)->update([$column => $forged]);
            try {
                $manager->post($actor, $context, $draft->id);
                $this->fail('Forged return header must fail closed for '.$column);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('goods_receipt_id', $exception->errors());
            }
            DB::table('enterprise_purchase_returns')
                ->where('id', $draft->id)->update([$column => $draft->{$column}]);
        }

        try {
            $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'lines' => [['goods_receipt_line_id' => $source->id, 'quantity' => '0.0004']],
            ]);
            $this->fail('A return below one milliquantity must not round into a posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.quantity', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_returns', [
            'id' => $draft->id, 'status' => 'draft', 'posted_at' => null,
        ]);
        $this->assertSame('0.875', number_format((float) DB::table('inventories')
            ->where('id', $inventory->id)->value('quantity'), 3, '.', ''));
        $this->assertSame(1, DB::table('stock_movements')
            ->where('type', 'purchase_return')
            ->where('reference_type', 'enterprise_purchase_return_line')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_return.posted')->count());

        $manager->post($actor, $context, $draft->id);
        $manager->post($actor, $context, $draft->id);
        $this->assertSame('0.625', number_format((float) DB::table('inventories')
            ->where('id', $inventory->id)->value('quantity'), 3, '.', ''));
        $this->assertSame('0.625', number_format((float) DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->value('quantity'), 3, '.', ''));
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventory->id)->count());
        $this->assertSame(2, DB::table('stock_movements')
            ->where('type', 'purchase_return')
            ->where('reference_type', 'enterprise_purchase_return_line')->count());
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_return.posted')->count());
        $this->assertDatabaseMissing('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id, 'owner_key' => 'FOODEX',
        ]);
        $this->assertDatabaseMissing('enterprise_inventory_owner_positions', [
            'inventory_id' => $inventory->id, 'owner_key' => 'SUPPLIER:'.$receipt->supplier_id,
        ]);
    }

    public function test_partial_returns_cannot_exceed_posted_receipt_remainder(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-B');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-B-SUP', 'PRTN-B-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseReturnManager::class);

        $first = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 2,
            ]],
        ]);
        $manager->post($actor, $context, $first->id);

        try {
            $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'lines' => [[
                    'goods_receipt_line_id' => $lineId,
                    'quantity' => 4,
                ]],
            ]);
            $this->fail('Draft creation must reject quantities beyond the current received remainder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $staleA = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 3,
            ]],
        ]);
        $staleB = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 3,
            ]],
        ]);

        $manager->post($actor, $context, $staleA->id);

        try {
            $manager->post($actor, $context, $staleB->id);
            $this->fail('Posting a stale draft must re-check the return remainder under the GRN lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_returns', [
            'id' => $staleB->id,
            'status' => 'draft',
        ]);
        $this->assertSame(
            0.0,
            (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
        $this->assertSame(
            2,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'purchase_return')
                ->count(),
        );
    }

    public function test_return_cannot_reduce_inventory_below_reserved_quantity(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-C');
        [$product, $warehouse] = $this->stockTarget($store, 'PRTN-C-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-C-SUP', 'PRTN-C-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;

        DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->update(['reserved_quantity' => 4]);

        $manager = app(PurchaseReturnManager::class);
        $return = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 2,
            ]],
        ]);

        try {
            $manager->post($actor, $context, $return->id);
            $this->fail('Return posting must preserve reserved stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('returned_quantity', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_returns', [
            'id' => $return->id,
            'status' => 'draft',
        ]);
        $this->assertSame(
            5.0,
            (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'),
        );
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('type', 'purchase_return')
                ->where('reference_type', 'enterprise_purchase_return_line')
                ->count(),
        );
    }

    public function test_purchase_return_reads_are_bounded_and_cross_store_isolated(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'PRTN-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PRTN-D-B');
        [, $warehouseA] = $this->stockTarget($storeA, 'PRTN-D-A-SKU');
        $receipt = $this->postedReceipt($actorA, $contextA, $warehouseA, 'PRTN-D-SUP', 'PRTN-D-A-SKU', 1);
        $return = app(PurchaseReturnManager::class)->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 1,
            ]],
        ]);

        $manager = app(PurchaseReturnManager::class);
        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        try {
            $manager->detail($actorB, $contextB, $return->id);
            $this->fail('Cross-store purchase return detail must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_purchase_return_number_prefix_search_is_literal_scoped_and_read_only(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-LIT-A');
        [$foreignActor, $foreignStore, $foreignContext] = $this->scopedUser(
            $this->allPermissions(), 'PRTN-LIT-B',
        );
        [, $warehouse] = $this->stockTarget($store, 'PRTN-LIT-A-SKU');
        [, $foreignWarehouse] = $this->stockTarget($foreignStore, 'PRTN-LIT-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-LIT-A-SUP', 'PRTN-LIT-A-SKU', 8);
        $foreignReceipt = $this->postedReceipt(
            $foreignActor, $foreignContext, $foreignWarehouse, 'PRTN-LIT-B-SUP', 'PRTN-LIT-B-SKU', 2,
        );
        $manager = app(PurchaseReturnManager::class);
        $makeReturn = static function (
            User $user,
            ResolvedTenantContext $tenant,
            EnterpriseGoodsReceipt $source
        ) use ($manager): int {
            return (int) $manager->createFromPostedReceipt($user, $tenant, $source->id, [
                'lines' => [[
                    'goods_receipt_line_id' => $source->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ])->id;
        };

        $target = $makeReturn($actor, $context, $receipt);
        $decoy = $makeReturn($actor, $context, $receipt);
        $leading = [];
        foreach ([
            ['%', '%P-001'],
            ['_', '_U-001'],
            ['!', '!B-001'],
            ['\\', '\\S-001'],
        ] as [$symbol, $number]) {
            $leading[$symbol] = $makeReturn($actor, $context, $receipt);
            DB::table('enterprise_purchase_returns')
                ->where('id', $leading[$symbol])
                ->update(['return_number' => $number]);
        }
        $foreign = $makeReturn($foreignActor, $foreignContext, $foreignReceipt);

        foreach ([
            [$target, 'RTN_%!\\-A'],
            [$decoy, 'RTNX-OTHER'],
            [$foreign, 'RTN_%!\\-FOREIGN'],
        ] as [$id, $number]) {
            DB::table('enterprise_purchase_returns')
                ->where('id', $id)
                ->update(['return_number' => $number]);
        }

        $returnCount = DB::table('enterprise_purchase_returns')->count();
        $auditCount = DB::table('audit_logs')->count();
        $movementCount = DB::table('stock_movements')->count();
        $search = fn (string $q): array => $manager->paginate($actor, $context, ['q' => $q])
            ->getCollection()->pluck('id')->all();

        foreach (['RTN_', 'RTN_%', 'RTN_%!', 'RTN_%!\\-'] as $q) {
            $this->assertSame([$target], $search($q), 'Incorrect literal return prefix: '.$q);
        }
        foreach ($leading as $symbol => $id) {
            $this->assertSame([$id], $search($symbol), 'Symbol search widened: '.$symbol);
        }
        $this->assertSame([$decoy, $target], $search('RTN'));
        $this->assertSame([], $search('RTN%'));
        $this->assertSame(1, $manager->paginate($foreignActor, $foreignContext, [
            'q' => 'RTN_%!',
        ])->total());
        $this->assertSame(0, $manager->paginate($actor, $context, [
            'q' => 'RTN', 'status' => 'posted',
        ])->total());
        $this->assertSame(0, $manager->paginate($actor, $context, [
            'q' => 'RTN', 'goods_receipt_id' => $foreignReceipt->id,
        ])->total());
        $this->assertSame(10, $manager->paginate($actor, $context, [], 1)->perPage());
        $this->assertSame(100, $manager->paginate($actor, $context, [], 500)->perPage());

        $roleless = User::factory()->create(['is_active' => true]);
        try {
            $manager->paginate($roleless, $context, ['q' => 'RTN_%']);
            $this->fail('Roleless actors cannot read purchase returns.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($returnCount, DB::table('enterprise_purchase_returns')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_draft_cancel_is_retry_safe_and_posted_return_is_immutable(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PRTN-E');
        [, $warehouse] = $this->stockTarget($store, 'PRTN-E-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PRTN-E-SUP', 'PRTN-E-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseReturnManager::class);

        $draft = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $cancelled = $manager->cancel($actor, $context, $draft->id);
        $again = $manager->cancel($actor, $context, $draft->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->cancelled_at?->toISOString(), $again->cancelled_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_return.cancelled')
                ->where('auditable_id', $draft->id)
                ->count(),
        );

        $postedReturn = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $manager->post($actor, $context, $postedReturn->id);

        $this->expectException(ValidationException::class);
        $manager->cancel($actor, $context, $postedReturn->id);
    }

    public function test_purchase_return_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_074500_create_enterprise_purchase_return_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_purchase_return_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_purchase_returns'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_returns', [
            'goods_receipt_id',
            'organization_id',
            'company_id',
            'store_id',
            'status',
            'posted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_return_lines', [
            'goods_receipt_line_id',
            'returned_quantity',
            'warehouse_id',
            'inventory_id',
            'stock_movement_id',
        ]));
    }

    public function test_returns_workbench_links_to_actual_authorized_return_and_renders_detail_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->enableProcurement();

        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P243-OWN');
        [, $warehouse] = $this->stockTarget($store, 'P243-OWN-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'P243-OWN-SUP', 'P243-OWN-SKU', 2);
        $return = app(PurchaseReturnManager::class)->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 1,
            ]],
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'returns',
                    'store_id' => $store->id,
                ]))
                ->assertOk()
                ->assertViewHas('activeTab', 'returns')
                ->assertSee('return='.$return->id, false)
                ->assertSee(__('enterprise_procurement.view'))
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'returns',
                    'store_id' => $store->id,
                    'return' => $return->id,
                ]))
                ->assertOk()
                ->assertViewHas('detail', static fn ($detail): bool => (int) $detail->id === (int) $return->id
                    && $detail->lines->count() === 1
                    && $detail->supplier !== null)
                ->assertSee('data-return-detail="'.$return->id.'"', false)
                ->assertSee($return->return_number)
                ->assertSee($receipt->receipt_number)
                ->assertSee('1.000')
                ->assertSee(__('enterprise_procurement.quantity'))
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_returns_workbench_denies_foreign_store_record_and_missing_permission(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->enableProcurement();

        [$owner, $store, $context] = $this->scopedUser($this->allPermissions(), 'P243-SEC-OWNER');
        [, $warehouse] = $this->stockTarget($store, 'P243-SEC-SKU');
        $receipt = $this->postedReceipt($owner, $context, $warehouse, 'P243-SEC-SUP', 'P243-SEC-SKU', 2);
        $return = app(PurchaseReturnManager::class)->createFromPostedReceipt($owner, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 1,
            ]],
        ]);

        [$foreign, $otherStore] = $this->scopedUser($this->allPermissions(), 'P243-SEC-FOREIGN');

        // A Wholesale manager can ordinarily access multiple B2B stores.
        // An explicit STORE DENY must still win when a forged request selects
        // this other company's return; ordinary own-store reads remain valid.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $foreign->id,
            'permission_id' => Permission::query()
                ->where('code', 'procurement.purchase_returns.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreign->forceFill(['has_permission_overrides' => true])->save();

        [$limited, $limitedStore] = $this->scopedUser(['procurement.suppliers.view'], 'P243-SEC-LIMITED');
        $this->grantPlatformErpAccess($limited);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $foreign->forceFill(['locale' => $locale])->save();
            $limited->forceFill(['locale' => $locale])->save();

            $this->actingAs($foreign->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'returns',
                    'store_id' => $store->id,
                    'return' => $return->id,
                ]))
                ->assertForbidden();

            $this->actingAs($foreign->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'returns',
                    'store_id' => $otherStore->id,
                    'return' => $return->id,
                ]))
                ->assertNotFound();

            $this->actingAs($limited->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'returns',
                    'store_id' => $limitedStore->id,
                    'return' => $return->id,
                ]))
                ->assertForbidden();
        }
    }

    public function test_returns_workbench_fails_closed_when_module_disabled_or_actor_roleless(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        [$actor, $store] = $this->scopedUser($this->allPermissions(), 'P243-MODULE-OFF');
        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'returns',
                'store_id' => $store->id,
            ]))
            ->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->enableProcurement();
        $roleless = User::factory()->create(['is_active' => true]);

        $this->actingAs($roleless)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'returns',
                'store_id' => $store->id,
            ]))
            ->assertForbidden();
    }

    private function enableProcurement(): void
    {
        // P135 adds a signed workflow prerequisite. Keep this P110 test branch
        // independently runnable until that test-only helper is integrated.
        // Never provision trust keys or certification in live configuration.
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    private function postedReceipt(
        User $actor,
        ResolvedTenantContext $context,
        Warehouse $warehouse,
        string $supplierCode,
        string $sku,
        float $quantity,
    ): EnterpriseGoodsReceipt {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);

        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'Purchase return source receipt',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'Stock item '.$sku,
                'quantity' => $quantity,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        // Procurement is Wholesale-only. An independent B2B checker must
        // hold its own effective global role; the Retail checker fixture
        // cannot create a B2C context for a B2B warehouse.
        $checker = User::factory()->create(['is_active' => true]);
        $checkerRole = Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail();
        $checker->roles()->syncWithoutDetaching([$checkerRole->id]);
        $checkerContext = app(TenantContextResolver::class)->wholesale($checker, $context->storeId);
        $requests->approve($checker, $checkerContext, $request->id);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $order = $orders->issue($actor, $context, $order->id);

        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
            ]],
        ]);

        return $receipts->post($actor, $context, $receipt->id);
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store, string $sku): array
    {
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2b',
            'code' => 'prtn-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);

        $unitCode = 'EA-'.substr(md5($sku), 0, 10);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => $unitCode,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'WH-'.substr(md5($sku), 0, 12),
            'name' => $sku.' Return',
            'is_active' => true,
        ]);

        return [$product, $warehouse];
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        return [
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $storeCode),
            'name' => $storeCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createWholesaleStore($storeCode);

        // Wholesale RBAC intentionally ignores store-role permission grants.
        // Supply the test actor's requested permission subset through a real,
        // channel-allowed global Procurement role, without changing production
        // RBAC or giving the limited viewer the manager's permissions.
        $manager = count($permissions) === count($this->allPermissions());
        $channelRole = Role::query()->firstOrCreate(
            ['code' => $manager ? 'PROCUREMENT_MANAGER' : 'PROCUREMENT_BUYER'],
            [
                'name' => $manager ? 'Wholesale Procurement Manager' : 'Wholesale Procurement Buyer',
                'scope' => 'global',
                'is_active' => true,
                'is_system' => false,
            ],
        );
        $channelRole->permissions()->sync(
            Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
        );
        $user->roles()->syncWithoutDetaching([$channelRole->id]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->wholesale($user, $store->id),
        ];
    }

    private function createWholesaleStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
                'code' => 'B2B',
                'name' => 'Wholesale',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
