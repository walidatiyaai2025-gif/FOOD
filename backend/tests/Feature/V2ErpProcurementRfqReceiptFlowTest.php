<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: one authentic maker -> supplier RFQ -> checker -> PO -> receipt chain.
 * Version/signature evidence is synthetic *only* inside isolated PHPUnit.
 */
final class V2ErpProcurementRfqReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
    }

    public function test_real_http_maker_rfq_quote_checker_po_and_posted_receipt_keep_verified_supplier_scope_and_money(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        $this->assertNotSame($maker->id, $checker->id);
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'store_id' => $store->id, 'supplier_id' => null,
            'status' => 'submitted', 'created_by' => $maker->id, 'submitted_by' => $maker->id,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'purchase_request_id' => $requestId, 'store_id' => $store->id,
            'created_by' => $maker->id, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'submitted',
            'currency_code' => 'KWD', 'total_amount' => '8.500',
        ]);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertForbidden();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertForbidden();

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->first();
        $this->assertNotNull($order);
        $this->assertSame($requestId, (int) $order->purchase_request_id);
        $this->assertSame($supplierId, (int) $order->supplier_id);
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame($quoteId, (int) $order->awarded_quote_id);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id,
            'company_id' => DB::table('enterprise_purchase_requests')->where('id', $requestId)->value('company_id'),
            'status' => 'draft',
        ]);
        $line = DB::table('enterprise_purchase_order_lines')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('4.250', number_format((float) $line->unit_price, 3, '.', ''));
        $this->assertSame('2.000', number_format((float) $line->quantity, 3, '.', ''));

        // An authenticated second award reuses the same order, never charges twice.
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());

        // A different quote ID is not a replay of the awarded quote.
        // It must be rejected without changing the existing PO or audit trail.
        $awardAuditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId + 100000,
        ]), ['store_id' => $store->id])->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'awarded', 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'procurement_rfq_id' => $rfqId, 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());
        $this->assertSame($awardAuditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'issued_by' => $checker->id,
        ]);

        [$product, $warehouse] = $this->stockTarget($store);
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 2]],
        ])->assertRedirect();

        $receipt = DB::table('enterprise_goods_receipts')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($supplierId, (int) $receipt->supplier_id);
        $this->assertSame($store->id, (int) $receipt->store_id);
        $this->assertSame($order->company_id, $receipt->company_id);
        $this->assertSame('draft', $receipt->status);
        $this->assertDatabaseHas('enterprise_goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $line->id, 'warehouse_id' => $warehouse->id,
            'received_quantity' => '2.000',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $receipt->id, 'status' => 'posted', 'posted_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->whereIn('reference_id', DB::table('enterprise_goods_receipt_lines')
                ->where('goods_receipt_id', $receipt->id)->pluck('id'))->count());
        $this->assertSame(2.0, (float) DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
            ->value('quantity'));
        foreach ([
            'enterprise.purchase_request.approved',
            'enterprise.procurement.rfq.awarded',
            'enterprise.purchase_order.issued',
            'enterprise.goods_receipt.posted',
        ] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    public function test_supplier_portal_price_precision_rejects_unrepresentable_amounts_without_partial_writes(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');

        [, , $rfqId, $quoteId] = $this->quotedRequest($maker, $store, assertPricePrecision: true);

        // The real invitation URL accepts the three-decimal retry after both
        // rejected submissions. Neither rejected price creates a quote row.
        $this->assertSame(1, DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfqId)->count());
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'rfq_id' => $rfqId, 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
    }

    public function test_supplier_quote_amount_overflow_fails_without_partial_requote_then_preserves_exact_mills(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $line = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($line);
        $originalAuditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        // Both quantity and unit price independently pass their 0..999999999
        // input constraints. Their product exceeds DECIMAL(18,3) and must
        // never overwrite the existing legally submitted supplier quote.
        DB::table('enterprise_procurement_rfq_lines')
            ->where('id', $line->rfq_line_id)
            ->update(['quantity' => '999999999.000']);
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [[
                'rfq_line_id' => $line->rfq_line_id,
                'unit_price' => '999999999',
            ]],
        ])->assertRedirect()->assertSessionHasErrors('lines');

        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $line->id, 'quantity' => '2.000',
            'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertSame(1, DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->count());
        $this->assertSame($originalAuditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());

        // A subsequent valid invitation-token resubmission is permitted and
        // retains exact three-decimal currency values rather than floats.
        DB::table('enterprise_procurement_rfq_lines')
            ->where('id', $line->rfq_line_id)
            ->update(['quantity' => '2.000']);
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [[
                'rfq_line_id' => $line->rfq_line_id,
                'unit_price' => '0.001',
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'quote_id' => $quoteId, 'quantity' => '2.000',
            'unit_price' => '0.001', 'line_total' => '0.002',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'total_amount' => '0.002',
        ]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame($originalAuditCount + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_quote_attachment_persists_only_after_successful_audited_requote(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $sourceLineId = (int) DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->value('rfq_line_id');
        $this->assertGreaterThan(0, $sourceLineId);

        Storage::fake('local');
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [['rfq_line_id' => $sourceLineId, 'unit_price' => '0.005']],
            'attachment' => UploadedFile::fake()->create('supplier-quote.pdf', 20, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = DB::table('enterprise_procurement_quote_documents')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($document);
        $this->assertStringStartsWith('procurement/quotes/', $document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame([$document->storage_path], Storage::disk('local')->allFiles('procurement/quotes'));
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'rfq_id' => $rfqId, 'status' => 'submitted', 'total_amount' => '0.010',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'quote_id' => $quoteId, 'unit_price' => '0.005', 'line_total' => '0.010',
        ]);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_quote_attachment_is_deleted_when_late_audit_failure_rolls_back_requote(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $original = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($original);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        Storage::fake('local');
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            // QueryExecuted fires AFTER SQL persistence. This injects an
            // application failure after the uploaded file and quote-document
            // row were written, before the surrounding transaction can commit.
            if ($injected || ! str_contains(strtolower($query->sql), 'audit_logs')
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                return;
            }

            $injected = true;
            throw new \RuntimeException('Injected quote audit persistence failure');
        });

        $this->withoutExceptionHandling();
        try {
            $this->post(route('supplier.rfq.submit', ['token' => $token]), [
                'lines' => [[
                    'rfq_line_id' => $original->rfq_line_id, 'unit_price' => '7.000',
                ]],
                'attachment' => UploadedFile::fake()->create('supplier.pdf', 20, 'application/pdf'),
            ]);
            $this->fail('Late audit failure must reject the entire supplier quote submission.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected quote audit persistence failure', $exception->getMessage());
        }

        $this->assertTrue($injected, 'The injected failure must happen after SQL audit INSERT.');
        $this->assertSame([], Storage::disk('local')->allFiles('procurement/quotes'));
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $original->id, 'quantity' => '2.000',
            'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertSame(1, DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfqId)->count());
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_public_rfq_portal_and_quote_post_refuse_suspended_or_disqualified_supplier_without_writes(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [$supplierId, $requestId, $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $quoteLine = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($quoteLine);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('submitted', $invitation->status);

        // Supplier portal is a public bearer-token route: validate rejection
        // without retaining the original procurement administrator session.
        auth()->logout();

        $otherStore = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-RFQ-FOREIGN-SCOPE', 'name' => 'Foreign RFQ scope',
            'is_active' => true,
        ]);

        // Publication was authorized while supplier was active/in scope; an
        // old bearer token cannot override hold, disqualification or transfer
        // to a different store.
        foreach ([
            ['status' => 'on_hold', 'qualification_status' => 'qualified'],
            ['status' => 'active', 'qualification_status' => 'unqualified'],
            [
                'status' => 'active', 'qualification_status' => 'qualified',
                'scope_type' => 'store', 'store_id' => $otherStore->id,
            ],
        ] as $restriction) {
            DB::table('enterprise_suppliers')->where('id', $supplierId)->update($restriction);

            $this->get(route('supplier.rfq.show', ['token' => $token]))
                ->assertStatus(410);
            $this->post(route('supplier.rfq.submit', ['token' => $token]), [
                'lines' => [[
                    'rfq_line_id' => $quoteLine->rfq_line_id,
                    'unit_price' => '7.000',
                ]],
            ])->assertStatus(410);

            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $requestId, 'status' => 'submitted',
            ]);
            $this->assertDatabaseHas('enterprise_procurement_rfqs', [
                'id' => $rfqId, 'status' => 'published',
            ]);
            $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
                'id' => $invitation->id, 'status' => 'submitted',
                'last_accessed_at' => $invitation->last_accessed_at,
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
                'id' => $quoteLine->id, 'unit_price' => '4.250', 'line_total' => '8.500',
            ]);
            $this->assertSame(0, DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->count());
            $this->assertSame($auditCount, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.quote.submitted')->count());
        }

        // An explicitly restored active and qualified supplier can reuse
        // the original invitation without exposing anyone else's RFQ.
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'status' => 'active', 'qualification_status' => 'qualified',
            'scope_type' => 'store', 'store_id' => $store->id,
        ]);
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [[
                'rfq_line_id' => $quoteLine->rfq_line_id,
                'unit_price' => '6.000',
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '12.000',
        ]);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_hold_after_token_precheck_cannot_write_stale_quote_or_audit(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [$supplierId, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $line = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($line);
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        auth()->logout();
        $blocked = false;
        DB::listen(function (QueryExecuted $query) use ($supplierId, &$blocked): void {
            // The bearer-token lookup is legitimate. Procurement governance
            // changes immediately AFTER its SELECT but BEFORE quote writes.
            if ($blocked || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $blocked = true;
            DB::table('enterprise_suppliers')
                ->where('id', $supplierId)
                ->update(['status' => 'on_hold']);
        });

        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [['rfq_line_id' => $line->rfq_line_id, 'unit_price' => '7.000']],
        ])->assertStatus(410);

        $this->assertTrue($blocked, 'The supplier governance hold must occur after token lookup.');
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplierId, 'status' => 'on_hold',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $line->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
        $this->assertSame(0, DB::table('enterprise_procurement_quote_documents')
            ->where('quote_id', $quoteId)->count());
    }

    public function test_invited_token_does_not_record_opened_when_supplier_or_module_revoked_mid_get(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        $code = 'P135-RFQ-INVITE-LATE-'.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store', 'code' => $code,
            'name_en' => 'Late governance invite supplier', 'name_ar' => 'مورد اختبار الدعوة',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $reason = 'P135 untouched invited token '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'currency_code' => 'KWD', 'reason' => $reason,
            'item_code' => 'P135-RFQ-INVITED', 'description' => 'Public invite with late hold',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', $reason)->value('id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 late supplier hold before read mark',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');

        $links = session('procurement_portal_links');
        $this->assertCount(1, $links);
        $token = basename((string) parse_url((string) $links[0]['url'], PHP_URL_PATH));
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('invited', $invitation->status);
        $accessedAt = $invitation->last_accessed_at;
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();
        auth()->logout();

        $phase = 'supplier_hold';
        $tokenReads = 0;
        $injected = [];
        DB::listen(function (QueryExecuted $query) use (&$phase, &$tokenReads, &$injected, $supplierId): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $tokenReads++;
            if ($tokenReads !== 2) {
                return;
            }

            if ($phase === 'supplier_hold') {
                $injected[] = $phase;
                DB::table('enterprise_suppliers')->where('id', $supplierId)
                    ->update(['status' => 'on_hold']);
            } elseif ($phase === 'module_suspend') {
                $injected[] = $phase;
                app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
            }
        });

        // The first eligibility preflight succeeded; the supplier is held
        // after the second token SELECT, before the final authorization.
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertStatus(410);
        $this->assertSame(['supplier_hold'], $injected);
        $this->assertSame(2, $tokenReads);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'invited', 'last_accessed_at' => $accessedAt,
        ]);

        // Restored supplier is initially eligible, but Procurement is shut
        // down during the second token lookup. Deny without marking opened.
        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'active']);
        $phase = 'module_suspend';
        $tokenReads = 0;
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertNotFound();
        $this->assertSame(['supplier_hold', 'module_suspend'], $injected);
        $this->assertSame(2, $tokenReads);
        $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'invited', 'last_accessed_at' => $accessedAt,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quotes', 0);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());

        // Once both authorities are genuinely restored, a normal public GET
        // still records exactly the intended opened state.
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        $phase = 'none';
        $tokenReads = 0;
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
        $this->assertSame(2, $tokenReads);
        $opened = DB::table('enterprise_procurement_rfq_invitations')
            ->where('id', $invitation->id)->first();
        $this->assertNotNull($opened);
        $this->assertSame('opened', $opened->status);
        $this->assertNotNull($opened->last_accessed_at);
        $this->assertDatabaseCount('enterprise_procurement_quotes', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_submitted_supplier_get_reads_module_rfq_invitation_supplier_and_quote_inside_one_transaction(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('submitted', $invitation->status);
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        // RefreshDatabase creates an outer test transaction. The GET must
        // create an ADDITIONAL nested transaction for its own lock contract;
        // simply checking transactionLevel > 0 would produce a false pass.
        $baselineTransactionLevel = DB::transactionLevel();
        $lockedReads = [];
        $tables = [
            'enterprise_modules',
            'enterprise_procurement_rfqs',
            'enterprise_procurement_rfq_invitations',
            'enterprise_suppliers',
            'enterprise_procurement_quotes',
        ];
        DB::listen(function (QueryExecuted $query) use (
            $baselineTransactionLevel,
            $tables,
            &$lockedReads,
        ): void {
            if (DB::transactionLevel() <= $baselineTransactionLevel
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            foreach ($tables as $table) {
                if (str_contains($query->sql, $table) && ! in_array($table, $lockedReads, true)) {
                    $lockedReads[] = $table;
                    break;
                }
            }
        });

        auth()->logout();
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();

        $this->assertSame(
            $tables,
            $lockedReads,
            'Public GET must serialize all five authorities and the quote in the POST-compatible lock order.',
        );
        $this->assertSame($baselineTransactionLevel, DB::transactionLevel());
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id,
            'status' => 'submitted',
            'last_accessed_at' => $invitation->last_accessed_at,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId,
            'status' => 'submitted',
            'total_amount' => '8.500',
        ]);
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_portal_get_never_resurrects_revoked_invitation_after_stale_token_lookup(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $code = 'P135-RFQ-GET-'.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store', 'code' => $code,
            'name_en' => 'RFQ portal access supplier', 'name_ar' => 'مورد رابط العرض',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');

        $reason = 'P135 portal access revocation '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD', 'reason' => $reason,
            'item_code' => 'P135-RFQ-GET', 'description' => 'Portal token guarded by invitation',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')->where('reason', $reason)->value('id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 single-use RFQ invitation status',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');

        $links = session('procurement_portal_links');
        $this->assertCount(1, $links);
        $token = basename((string) parse_url((string) $links[0]['url'], PHP_URL_PATH));
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('invited', $invitation->status);

        // A legitimate initial portal GET can mark an active invitation as opened.
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'opened',
        ]);
        $originalAccessedAt = DB::table('enterprise_procurement_rfq_invitations')
            ->where('id', $invitation->id)->value('last_accessed_at');

        // Revocation occurs AFTER GET's original token lookup but BEFORE its
        // read-mark. Without the conditional UPDATE this resurrects the token.
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use (&$revoked, $invitation): void {
            if ($revoked || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $revoked = true;
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $invitation->id)
                ->update(['status' => 'revoked']);
        });

        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertStatus(410);
        $this->assertTrue($revoked, 'A token lookup must actually precede the revocation.');
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'revoked',
            'last_accessed_at' => $originalAccessedAt,
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quotes', 0);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
    }

    public function test_public_supplier_rfq_token_cannot_read_or_requote_when_procurement_module_is_not_enabled(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $line = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($line);
        $this->assertNotNull($invitation);

        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        auth()->logout();
        Storage::fake('local');

        // Real module lifecycle transitions: the supplier's anonymous bearer
        // token must not bypass an admin suspension, maintenance or shutdown.
        foreach ([
            ModuleState::SUSPENDED,
            ModuleState::MAINTENANCE,
            ModuleState::DISABLED,
        ] as $state) {
            app(ModuleLifecycleManager::class)->transition('procurement', $state);
            $this->assertSame($state, app(ModuleRegistry::class)->state('procurement'));

            $this->get(route('supplier.rfq.show', ['token' => $token]))
                ->assertNotFound();
            $this->post(route('supplier.rfq.submit', ['token' => $token]), [
                'lines' => [[
                    'rfq_line_id' => $line->rfq_line_id,
                    'unit_price' => '7.000',
                ]],
                'attachment' => UploadedFile::fake()->create('disabled-quote.pdf', 8, 'application/pdf'),
            ])->assertNotFound();

            $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
                'id' => $invitation->id,
                'status' => 'submitted',
                'last_accessed_at' => $invitation->last_accessed_at,
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
                'id' => $line->id, 'unit_price' => '4.250', 'line_total' => '8.500',
            ]);
            $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('procurement/quotes'));
            $this->assertSame($beforeAudits, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.quote.submitted')->count());
        }

        // Restoring the module explicitly allows its still-valid historical
        // supplier invitation to return, without changing the prior quote.
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [[
                'rfq_line_id' => $line->rfq_line_id,
                'unit_price' => '6.000',
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '12.000',
        ]);
        $this->assertSame($beforeAudits + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_quote_preflight_does_not_bypass_module_shutdown_before_locked_transaction(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $quoteLine = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($quoteLine);
        $this->assertNotNull($invitation);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        auth()->logout();
        Storage::fake('local');
        $shutdown = false;
        $transitioning = false;
        $moduleSelects = 0;
        DB::listen(function (QueryExecuted $query) use (&$shutdown, &$transitioning, &$moduleSelects): void {
            // Exclude SQL issued by ModuleLifecycleManager itself. Verify
            // the quote route actually performs its second, transaction-locked
            // module SELECT after a successful stale preflight query.
            if ($transitioning || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_modules')) {
                return;
            }

            $moduleSelects++;
            if ($shutdown) {
                return;
            }

            $shutdown = true;
            $transitioning = true;
            try {
                app(ModuleLifecycleManager::class)->transition(
                    'procurement',
                    ModuleState::SUSPENDED,
                );
            } finally {
                $transitioning = false;
            }
        });

        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [[
                'rfq_line_id' => $quoteLine->rfq_line_id,
                'unit_price' => '7.000',
            ]],
            'attachment' => UploadedFile::fake()->create('stale-module.pdf', 12, 'application/pdf'),
        ])->assertNotFound();

        $this->assertTrue($shutdown, 'The module must be suspended after the initial state SELECT.');
        $this->assertGreaterThanOrEqual(
            2,
            $moduleSelects,
            'The write transaction must independently re-check the locked module after preflight.',
        );
        $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'submitted',
            'last_accessed_at' => $invitation->last_accessed_at,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $quoteLine->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('procurement/quotes'));
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_submitted_supplier_portal_get_rejects_revocation_after_final_token_lookup(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('submitted', $invitation->status);
        $originalQuote = DB::table('enterprise_procurement_quotes')
            ->where('id', $quoteId)->first();
        $originalAccessedAt = $invitation->last_accessed_at;
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        auth()->logout();
        $tokenReads = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($invitation, &$tokenReads, &$revoked): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $tokenReads++;
            // GET validates a token once without side effects and once for
            // its guarded read-mark. A SUBMITTED token was previously returned
            // without any status re-read after this second SELECT.
            if ($tokenReads === 2) {
                $revoked = true;
                DB::table('enterprise_procurement_rfq_invitations')
                    ->where('id', $invitation->id)->update(['status' => 'revoked']);
            }
        });

        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertStatus(410);

        $this->assertTrue($revoked, 'Revocation must occur after the final token SELECT.');
        $this->assertSame(2, $tokenReads);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id,
            'status' => 'revoked',
            'last_accessed_at' => $originalAccessedAt,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId,
            'status' => 'submitted',
            'total_amount' => $originalQuote->total_amount,
        ]);
        $this->assertSame(0, DB::table('enterprise_procurement_quote_documents')
            ->where('quote_id', $quoteId)->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_submitted_supplier_portal_get_rejects_mid_request_supplier_hold_after_token_recheck(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [$supplierId, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $line = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($invitation);
        $this->assertNotNull($line);
        $this->assertSame('submitted', $invitation->status);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        auth()->logout();
        $tokenReads = 0;
        $held = false;
        DB::listen(function (QueryExecuted $query) use (&$tokenReads, &$held, $supplierId): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $tokenReads++;
            if ($tokenReads !== 2 || $held) {
                return;
            }

            // The first supplier eligibility read succeeded. An independent
            // governance hold now becomes visible AFTER token SELECT #2,
            // before the final RFQ and supplier authorization rechecks.
            $held = true;
            DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->update(['status' => 'on_hold']);
        });

        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertStatus(410);

        $this->assertTrue($held, 'Supplier hold must happen after the final invitation token read.');
        $this->assertSame(2, $tokenReads);
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplierId, 'status' => 'on_hold',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'submitted',
            'last_accessed_at' => $invitation->last_accessed_at,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $line->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());

        // An explicit admin restoration permits the unchanged original
        // submitted quote to be read again; a rejected GET does not revoke it.
        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'active']);
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'total_amount' => '8.500',
        ]);
    }

    public function test_submitted_supplier_portal_get_rejects_module_shutdown_after_token_lookup(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $line = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($invitation);
        $this->assertNotNull($line);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        auth()->logout();
        $tokenReads = 0;
        $shutdown = false;
        DB::listen(function (QueryExecuted $query) use (&$tokenReads, &$shutdown): void {
            if ($shutdown || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $tokenReads++;
            if ($tokenReads !== 2) {
                return;
            }

            // The original module preflight was ENABLED, but an operator
            // suspends Procurement AFTER the second token SELECT, before the
            // supplier's public GET reads historical quote/document details.
            $shutdown = true;
            app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
        });

        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertNotFound();

        $this->assertTrue($shutdown, 'Procurement must shut down after token preflight.');
        $this->assertSame(2, $tokenReads);
        $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'submitted',
            'last_accessed_at' => $invitation->last_accessed_at,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $line->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());

        // SUSPENDED -> ENABLED is the canonical resumption transition.
        // SUSPENDED -> ENABLING is explicitly illegal in ModuleState.
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertOk();
    }

    public function test_submitted_supplier_portal_get_rejects_rfq_cancelled_after_token_lookup(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('submitted', $invitation->status);
        $auditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();
        $beforeAccess = $invitation->last_accessed_at;

        auth()->logout();
        $tokenReads = 0;
        $cancelled = false;
        DB::listen(function (QueryExecuted $query) use (&$tokenReads, &$cancelled, $rfqId): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')) {
                return;
            }

            $tokenReads++;
            if ($tokenReads !== 2) {
                return;
            }

            // The RFQ is cancelled AFTER the original published-state read,
            // without revoking the already-SUBMITTED supplier invitation.
            // A public GET must not render historical quote/document metadata
            // using a stale RFQ object after final token revalidation.
            $cancelled = true;
            DB::table('enterprise_procurement_rfqs')
                ->where('id', $rfqId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $this->get(route('supplier.rfq.show', ['token' => $token]))->assertStatus(410);

        $this->assertTrue($cancelled, 'RFQ must be cancelled after its first published-state SELECT.');
        $this->assertSame(2, $tokenReads);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'id' => $invitation->id, 'status' => 'submitted',
            'last_accessed_at' => $beforeAccess,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertDatabaseCount('enterprise_procurement_quote_documents', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_quote_cannot_override_cancelled_rfq_after_pretransaction_read(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $original = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($original);
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        // Force a genuine stale-source interleaving: a separate admin
        // cancellation becomes visible AFTER the initial RFQ SELECT but
        // BEFORE the supplier transaction locks and revalidates the RFQ.
        $cancelled = false;
        DB::listen(function (QueryExecuted $query) use ($rfqId, &$cancelled): void {
            if ($cancelled || ! str_contains($query->sql, 'enterprise_procurement_rfqs')
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            $cancelled = true;
            DB::table('enterprise_procurement_rfqs')->where('id', $rfqId)
                ->update(['status' => 'cancelled']);
        });

        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [['rfq_line_id' => $original->rfq_line_id, 'unit_price' => '7.000']],
        ])->assertStatus(410);

        $this->assertTrue($cancelled, 'The stale-read interleaving must actually occur.');
        $this->assertDatabaseHas('enterprise_procurement_rfqs', ['id' => $rfqId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $original->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertSame(1, DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfqId)->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_supplier_quote_cannot_revive_invitation_revoked_after_token_precheck(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        [, , $rfqId, $quoteId, $token] = $this->quotedRequest($maker, $store);
        $original = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($original);
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count();

        // A real token_hash SELECT succeeds with the old invitation state.
        // Revocation immediately after that first read must still deny the
        // supplier at the transaction's locking re-check.
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($token, &$revoked): void {
            if ($revoked || ! str_contains($query->sql, 'enterprise_procurement_rfq_invitations')
                || ! str_contains($query->sql, 'token_hash')
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            $revoked = true;
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('token_hash', hash('sha256', $token))
                ->update(['status' => 'revoked']);
        });

        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'lines' => [['rfq_line_id' => $original->rfq_line_id, 'unit_price' => '7.000']],
        ])->assertStatus(410);

        $this->assertTrue($revoked, 'The invitation revocation must actually occur.');
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'token_hash' => hash('sha256', $token), 'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quote_lines', [
            'id' => $original->id, 'unit_price' => '4.250', 'line_total' => '8.500',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
        ]);
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.quote.submitted')->count());
    }

    public function test_invoice_match_approved_snapshot_and_finance_outbox_reject_forged_source_and_replay(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')->where('procurement_rfq_id', $rfqId)->first();
        $this->assertNotNull($order);
        $line = DB::table('enterprise_purchase_order_lines')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($line);

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        [, $warehouse] = $this->stockTarget($store);
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '2.000']],
        ])->assertRedirect();
        $receipt = DB::table('enterprise_goods_receipts')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $receiptLineId = (int) DB::table('enterprise_goods_receipt_lines')
            ->where('goods_receipt_id', $receipt->id)->value('id');
        $this->assertGreaterThan(0, $receiptLineId);

        // Commercial supplier, currency, tax and total claims in HTTP body
        // must not replace server-authoritative receipt and line snapshots.
        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.store', $receipt->id), [
            'store_id' => $store->id, 'supplier_id' => 999999, 'currency_code' => 'USD',
            'supplier_invoice_number' => 'P135-MATCH-REF-001',
            'invoice_date' => now()->toDateString(), 'total_amount' => '0.001',
            'tax_amount' => '999.000',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLineId,
                'quantity' => '2.000', 'unit_price' => '5.000',
            ]],
        ])->assertRedirect();
        $invoice = DB::table('enterprise_purchase_invoices')
            ->where('supplier_invoice_number', 'P135-MATCH-REF-001')->first();
        $this->assertNotNull($invoice);
        $this->assertSame((int) $supplierId, (int) $invoice->supplier_id);
        $this->assertSame('KWD', $invoice->currency_code);
        $this->assertSame('10.000', number_format((float) $invoice->total_amount, 3, '.', ''));
        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
            'status' => 'pending',
            'value_variance' => '1.500',
        ]);
        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.store', $receipt->id), [
            'store_id' => $store->id,
            'supplier_invoice_number' => 'P135-MATCH-REF-001',
            'invoice_date' => now()->toDateString(),
            'lines' => [['goods_receipt_line_id' => $receiptLineId, 'quantity' => '1.000']],
        ])->assertRedirect()->assertSessionHasErrors('supplier_invoice_number');

        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.handoff', $invoice->id), [
            'store_id' => $store->id,
        ])->assertRedirect()->assertSessionHasErrors('three_way_match');
        $this->actingAs($checker)->post(route('admin.erp.procurement.matches.review', $invoice->id), [
            'store_id' => $store->id, 'decision' => 'approve', 'note' => 'Reviewed source variance',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id, 'status' => 'approved',
        ]);

        foreach ([
            ['total_amount', '11.000'],
            ['currency_code', 'USD'],
            ['source_order_number', 'FORGED-PO-NUMBER'],
        ] as [$field, $value]) {
            DB::table('enterprise_purchase_invoices')->where('id', $invoice->id)
                ->update([$field => $value]);
            $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.handoff', $invoice->id), [
                'store_id' => $store->id,
            ])->assertRedirect()->assertSessionHasErrors('three_way_match');
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id, 'status' => 'draft', 'outbox_event_id' => null,
            ]);
            DB::table('enterprise_purchase_invoices')->where('id', $invoice->id)->update([
                $field => match ($field) {
                    'total_amount' => '10.000',
                    'currency_code' => 'KWD',
                    default => $order->order_number,
                },
            ]);
        }

        $invoiceLineId = (int) DB::table('enterprise_purchase_invoice_lines')
            ->where('purchase_invoice_id', $invoice->id)->value('id');
        DB::table('enterprise_purchase_invoice_lines')->where('id', $invoiceLineId)
            ->update(['unit_price' => '6.000', 'line_total' => '12.000']);
        DB::table('enterprise_purchase_invoices')->where('id', $invoice->id)
            ->update(['total_amount' => '12.000']);
        $match = app(ProcurementCompletionManager::class)
            ->syncMatchForInvoice((int) $invoice->id);
        $this->assertSame('pending', $match->status);
        $this->assertNull($match->reviewed_by);
        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.handoff', $invoice->id), [
            'store_id' => $store->id,
        ])->assertRedirect()->assertSessionHasErrors('three_way_match');

        $this->assertSame(0, DB::table('enterprise_outbox_messages')
            ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count());
        $this->assertDatabaseCount('enterprise_finance_source_postings', 0);

        $this->actingAs($checker)->post(route('admin.erp.procurement.matches.review', $invoice->id), [
            'store_id' => $store->id, 'decision' => 'approve', 'note' => 'Re-reviewed corrected variance',
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.handoff', $invoice->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.invoices.handoff', $invoice->id), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $this->assertSame(1, DB::table('enterprise_outbox_messages')
            ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_invoice.handed_off')
            ->where('auditable_id', $invoice->id)->count());
        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $invoice->id, 'status' => 'handed_off', 'handed_off_by' => $checker->id,
        ]);
        $this->assertDatabaseCount('enterprise_finance_source_postings', 0);

        // A handed-off invoice is immutable even at its match review route.
        $this->actingAs($checker)->post(route('admin.erp.procurement.matches.review', $invoice->id), [
            'store_id' => $store->id, 'decision' => 'reject',
        ])->assertRedirect()->assertSessionHasErrors('status');
        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id, 'status' => 'approved',
        ]);
    }

    public function test_receipt_http_rejects_extra_precision_and_overreceipt_without_duplicate_stock_posting(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();

        $order = DB::table('enterprise_purchase_orders')->where('procurement_rfq_id', $rfqId)->first();
        $this->assertNotNull($order);
        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued',
        ]);
        $line = DB::table('enterprise_purchase_order_lines')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($line);
        [$product, $warehouse] = $this->stockTarget($store);

        // The database stores three decimal places; never silently round a
        // sub-milliquantity or a four-decimal receipt into real inventory.
        foreach (['0.0009', '0.6251'] as $invalidQuantity) {
            $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
                'store_id' => $store->id, 'warehouse_id' => $warehouse->id,
                'lines' => [[
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $invalidQuantity,
                ]],
            ])->assertRedirect()->assertSessionHasErrors('lines.0.quantity');
        }
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id, 'warehouse_id' => $warehouse->id,
            'owner_key' => 'FOODEX', 'discount_percent' => 100,
            'lines' => [[
                'purchase_order_line_id' => $line->id, 'quantity' => '0.625',
                'unit' => 'case', 'unit_price' => '0.000',
            ]],
        ])->assertRedirect();
        $receipt = DB::table('enterprise_goods_receipts')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $receiptLine = DB::table('enterprise_goods_receipt_lines')->where('goods_receipt_id', $receipt->id)->first();
        $this->assertNotNull($receiptLine);
        $this->assertSame('each', $receiptLine->unit);
        $this->assertSame('4.250', number_format((float) $receiptLine->unit_price, 3, '.', ''));
        $this->assertSame('0.625', number_format((float) $receiptLine->received_quantity, 3, '.', ''));

        // A buyer cannot post stock by forging the route/actor. The
        // authorized poster may retry, but one GRN line produces one entry.
        $this->actingAs($maker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertForbidden();
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();

        // Even a three-decimal amount must not over-receive the PO remainder.
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id, 'warehouse_id' => $warehouse->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id, 'quantity' => '1.376',
            ]],
        ])->assertRedirect()->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('enterprise_goods_receipts', 1);
        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $receipt->id, 'status' => 'posted', 'posted_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('type', 'purchase_receipt')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->where('reference_id', $receiptLine->id)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')
            ->where('auditable_id', $receipt->id)->count());
        $this->assertSame('0.625', number_format((float) DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->value('quantity'), 3, '.', ''));
    }

    public function test_posted_receipt_fails_closed_for_tampered_foreign_warehouse_then_recovers_without_stock_or_audit_duplication(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        $this->assertNotSame($maker->id, $checker->id);

        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);
        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();

        $order = DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->first();
        $this->assertNotNull($order);
        $this->assertSame($supplierId, (int) $order->supplier_id);
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('KWD', (string) $order->currency_code);
        $line = DB::table('enterprise_purchase_order_lines')
            ->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('4.250', number_format((float) $line->unit_price, 3, '.', ''));

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued',
        ]);

        [$product, $warehouse] = $this->stockTarget($store);
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 2]],
        ])->assertRedirect();
        $receipt = DB::table('enterprise_goods_receipts')
            ->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($supplierId, (int) $receipt->supplier_id);
        $this->assertSame($store->id, (int) $receipt->store_id);
        $this->assertSame($order->company_id, $receipt->company_id);
        $receiptLine = DB::table('enterprise_goods_receipt_lines')
            ->where('goods_receipt_id', $receipt->id)->first();
        $this->assertNotNull($receiptLine);

        $foreign = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-GRN-FOREIGN-TENANT',
            'name' => 'P135 foreign receipt scope',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreign);
        $foreignWarehouse = Warehouse::query()->create([
            'store_id' => $foreign->id,
            'code' => 'P135-GRN-FOREIGN-WH',
            'name' => 'P135 foreign warehouse',
            'is_active' => true,
        ]);
        // Simulate a corrupted imported draft after the HTTP create-time
        // store check: posting must recheck persisted warehouse authority.
        DB::table('enterprise_goods_receipt_lines')->where('id', $receiptLine->id)
            ->update(['warehouse_id' => $foreignWarehouse->id]);
        $beforePostedAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')->count();
        $beforeMovements = DB::table('stock_movements')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $this->actingAs($checker->fresh())
                ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                    'store_id' => $store->id,
                ])
                ->assertRedirect()->assertSessionHasErrors('warehouse_id');
            $this->assertDatabaseHas('enterprise_goods_receipts', [
                'id' => $receipt->id, 'supplier_id' => $supplierId,
                'store_id' => $store->id, 'status' => 'draft', 'posted_by' => null,
            ]);
            $this->assertDatabaseHas('enterprise_goods_receipt_lines', [
                'id' => $receiptLine->id, 'warehouse_id' => $foreignWarehouse->id,
                'stock_movement_id' => null, 'inventory_id' => null,
            ]);
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'supplier_id' => $supplierId,
                'store_id' => $store->id, 'status' => 'issued',
            ]);
            $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
            $this->assertSame($beforePostedAudits, DB::table('audit_logs')
                ->where('event', 'enterprise.goods_receipt.posted')->count());
            $this->assertNull(DB::table('inventories')
                ->where('warehouse_id', $foreignWarehouse->id)
                ->where('product_id', $product->id)->value('quantity'));
        }

        // Repair the receiving warehouse first. A valid warehouse must NOT
        // forgive a forged supplier, currency or PO source on the persisted
        // GRN header. Use a REAL supplier from the foreign store, not an
        // invented numeric identifier.
        DB::table('enterprise_goods_receipt_lines')->where('id', $receiptLine->id)
            ->update(['warehouse_id' => $warehouse->id]);
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $foreign->id,
            'scope_type' => 'store',
            'code' => 'P135-GRN-FOREIGN-SUP-'.$maker->id,
            'name_en' => 'P135 foreign source supplier',
            'name_ar' => 'مورد مصدر مختلف',
        ])->assertRedirect();
        $foreignSupplierId = (int) DB::table('enterprise_suppliers')
            ->where('code', 'P135-GRN-FOREIGN-SUP-'.$maker->id)->value('id');
        $this->assertGreaterThan(0, $foreignSupplierId);
        $this->assertNotSame($supplierId, $foreignSupplierId);
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $foreignSupplierId, 'scope_type' => 'store',
            'store_id' => $foreign->id,
        ]);

        $trustedReceiptHeader = [
            'supplier_id' => $supplierId,
            'supplier_code' => $order->supplier_code,
            'currency_code' => $order->currency_code,
            'source_order_number' => $order->order_number,
        ];
        foreach ([
            ['supplier_id' => $foreignSupplierId],
            ['supplier_code' => 'P135-FORGED-SUPPLIER-CODE'],
            ['currency_code' => 'USD'],
            ['source_order_number' => 'P135-FORGED-PO-NUMBER'],
        ] as $tamper) {
            DB::table('enterprise_goods_receipts')->where('id', $receipt->id)
                ->update($tamper);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $checker->forceFill(['locale' => $locale])->save();
                $this->actingAs($checker->fresh())
                    ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                        'store_id' => $store->id,
                    ])->assertRedirect()->assertSessionHasErrors('purchase_order_id');
                $this->assertSame(
                    __('enterprise_procurement.errors.receipt_source_invalid', [], $locale),
                    session('errors')->first('purchase_order_id'),
                );
                $this->assertDatabaseHas('enterprise_goods_receipts', [
                    'id' => $receipt->id, 'store_id' => $store->id,
                    'status' => 'draft', 'posted_by' => null,
                ]);
                $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
                $this->assertSame($beforePostedAudits, DB::table('audit_logs')
                    ->where('event', 'enterprise.goods_receipt.posted')->count());
                $this->assertNull(DB::table('inventories')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)->value('quantity'));
                $this->assertNull(DB::table('inventories')
                    ->where('warehouse_id', $foreignWarehouse->id)
                    ->where('product_id', $product->id)->value('quantity'));
                $this->assertDatabaseHas('enterprise_purchase_orders', [
                    'id' => $order->id, 'supplier_id' => $supplierId,
                    'store_id' => $store->id, 'currency_code' => 'KWD',
                    'status' => 'issued',
                ]);
            }
            DB::table('enterprise_goods_receipts')->where('id', $receipt->id)
                ->update($trustedReceiptHeader);
        }

        // A persisted draft can be altered after valid HTTP creation. The
        // posting boundary must reauthenticate every PO-line snapshot field
        // and reject zero/negative quantities before any stock or GL write.
        $trustedLine = [
            'item_code' => $line->item_code,
            'unit' => $line->unit,
            'ordered_quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'received_quantity' => '2.000',
        ];
        foreach ([
            ['item_code' => 'FORGED-ITEM'],
            ['unit' => 'forged-unit'],
            ['ordered_quantity' => '1.000'],
            ['unit_price' => '0.001'],
            ['received_quantity' => '0.000'],
            ['received_quantity' => '-1.000'],
        ] as $tamper) {
            DB::table('enterprise_goods_receipt_lines')
                ->where('id', $receiptLine->id)->update($tamper);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $checker->forceFill(['locale' => $locale])->save();
                $this->actingAs($checker->fresh())
                    ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                        'store_id' => $store->id,
                    ])->assertRedirect()->assertSessionHasErrors('lines');
                $this->assertSame(
                    __('enterprise_procurement.errors.receipt_source_invalid', [], $locale),
                    session('errors')->first('lines'),
                );
                $this->assertDatabaseHas('enterprise_goods_receipts', [
                    'id' => $receipt->id, 'status' => 'draft', 'posted_by' => null,
                ]);
                $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
                $this->assertSame($beforePostedAudits, DB::table('audit_logs')
                    ->where('event', 'enterprise.goods_receipt.posted')->count());
                $this->assertNull(DB::table('inventories')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)->value('quantity'));
            }
            DB::table('enterprise_goods_receipt_lines')
                ->where('id', $receiptLine->id)->update($trustedLine);
        }

        // An entirely restored authentic GRN posts ONCE; a retry is idempotent
        // and never duplicates inventory, award, stock movement or audit.
        $this->actingAs($checker->fresh())
            ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                'store_id' => $store->id,
            ])->assertRedirect();
        $this->actingAs($checker->fresh())
            ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                'store_id' => $store->id,
            ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $receipt->id, 'supplier_id' => $supplierId,
            'store_id' => $store->id, 'status' => 'posted', 'posted_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->where('reference_id', $receiptLine->id)->count());
        $this->assertSame(2.0, (float) DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertNull(DB::table('inventories')
            ->where('warehouse_id', $foreignWarehouse->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame($beforePostedAudits + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')->count());

        // A posted status is not an authorization bypass: after an imported
        // header corruption, an idempotent retry must refuse a false success
        // even though it will never generate a second stock movement.
        DB::table('enterprise_goods_receipts')->where('id', $receipt->id)
            ->update(['currency_code' => 'USD']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $this->actingAs($checker->fresh())
                ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                    'store_id' => $store->id,
                ])->assertRedirect()->assertSessionHasErrors('purchase_order_id');
            $this->assertSame(
                __('enterprise_procurement.errors.receipt_source_invalid', [], $locale),
                session('errors')->first('purchase_order_id'),
            );
            $this->assertSame(1, DB::table('stock_movements')
                ->where('reference_type', 'enterprise_goods_receipt_line')
                ->where('reference_id', $receiptLine->id)->count());
            $this->assertSame($beforePostedAudits + 1, DB::table('audit_logs')
                ->where('event', 'enterprise.goods_receipt.posted')->count());
            $this->assertDatabaseHas('enterprise_goods_receipts', [
                'id' => $receipt->id, 'supplier_id' => $supplierId,
                'store_id' => $store->id, 'status' => 'posted',
            ]);
        }
        DB::table('enterprise_goods_receipts')->where('id', $receipt->id)
            ->update(['currency_code' => $order->currency_code]);

        // The repeated forged HTTP attempts above legitimately consume the
        // production 30/minute throttle. Advance the isolated test clock into
        // the next window before proving a restored request is idempotent;
        // do not disable route throttling or weaken its denial assertions.
        $this->travel(61)->seconds();
        $this->actingAs($checker->fresh())
            ->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
                'store_id' => $store->id,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($beforePostedAudits + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.goods_receipt.posted')->count());
    }

    public function test_real_http_rfq_contract_binding_requires_live_scoped_agreement_and_denies_terminated_contract(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        $supplierCode = 'P135-CONTRACT-RFQ-'.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => 'Contract-bound RFQ supplier', 'name_ar' => 'مورد عقد الطلب',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')
            ->where('code', $supplierCode)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $title = 'P135 binding contract '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.contracts.store'), [
            'store_id' => $store->id, 'supplier_id' => $supplierId,
            'title' => $title, 'currency_code' => 'KWD',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ])->assertRedirect();
        $contractId = (int) DB::table('enterprise_procurement_contracts')
            ->where('title', $title)->value('id');
        $this->assertGreaterThan(0, $contractId);
        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.activate', $contractId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'active', 'activated_by' => $checker->id,
        ]);

        $reason = 'P135 RFQ contract bound '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'currency_code' => 'KWD',
            'reason' => $reason, 'item_code' => 'P135-CONTRACT-SKU',
            'description' => 'Approved contract backing',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '4.250',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', $reason)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id,
            'purchase_request_id' => $requestId,
            'contract_id' => $contractId,
            'title' => 'P135 locked active contract',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'purchase_request_id' => $requestId, 'contract_id' => $contractId, 'status' => 'draft',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.terminate', $contractId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'terminated',
        ]);

        $secondReason = 'P135 terminated contract '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'currency_code' => 'KWD',
            'reason' => $secondReason, 'item_code' => 'P135-CONTRACT-SKU',
            'description' => 'Reject terminated agreement',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $secondRequest = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', $secondReason)->value('id');
        $this->assertGreaterThan(0, $secondRequest);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $secondRequest), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $audits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count();
        app()->setLocale('en');
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id,
            'purchase_request_id' => $secondRequest,
            'contract_id' => $contractId,
            'title' => 'P135 rejected terminated contract',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect()->assertSessionHasErrors('contract_id');
        $this->assertDatabaseMissing('enterprise_procurement_rfqs', [
            'purchase_request_id' => $secondRequest,
        ]);
        $this->assertSame($audits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count());
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $secondRequest, 'status' => 'submitted', 'supplier_id' => null,
        ]);
    }

    public function test_contract_activation_and_termination_are_transactional_and_fail_closed_after_module_shutdown(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        $code = 'P135-CONT-LOCK-'.$maker->id;
        $title = 'P135 lifecycle contract '.$maker->id;

        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store',
            'code' => $code, 'name_en' => 'Contract lifecycle lock supplier',
            'name_ar' => 'مورد اختبار قفل العقد',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.contracts.store'), [
            'store_id' => $store->id, 'supplier_id' => $supplierId,
            'title' => $title, 'currency_code' => 'KWD',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ])->assertRedirect();
        $contractId = (int) DB::table('enterprise_procurement_contracts')
            ->where('title', $title)->value('id');
        $this->assertGreaterThan(0, $contractId);

        $beforeActivate = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.activated')->count();
        $beforeTerminate = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.terminated')->count();
        $pendingShutdown = true;
        $insideLifecycleTransition = false;
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$pendingShutdown, &$insideLifecycleTransition, &$reads): void {
            if ($insideLifecycleTransition) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            if ($pendingShutdown && str_contains($query->sql, 'enterprise_modules')
                && DB::connection()->transactionLevel() === 1) {
                $pendingShutdown = false;
                $insideLifecycleTransition = true;
                try {
                    app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
                } finally {
                    $insideLifecycleTransition = false;
                }
            }
            if (DB::connection()->transactionLevel() < 2) {
                return;
            }
            if (str_contains($query->sql, 'enterprise_modules')) {
                $reads[] = 'module';
            } elseif (str_contains($query->sql, 'enterprise_procurement_contracts')) {
                $reads[] = 'contract';
            }
        });

        // Arabic checker rejection: the source's stale preflight is not an
        // authorization to activate an agreement after operator shutdown.
        app()->setLocale('ar');
        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.activate', $contractId), [
            'store_id' => $store->id,
        ])->assertForbidden();
        $this->assertFalse($pendingShutdown);
        // A suspended module is rejected by the HTTP access gate before the
        // mutation transaction. The re-enabled positive path proves lock order.
        $this->assertSame([], $reads);
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'draft', 'activated_by' => null,
        ]);
        $this->assertSame($beforeActivate, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.activated')->count());

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        $reads = [];
        app()->setLocale('en');
        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.activate', $contractId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertSame(['module', 'contract'], array_slice(array_values(array_unique($reads)), 0, 2));
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'active', 'activated_by' => $checker->id,
        ]);
        $this->assertSame($beforeActivate + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.activated')->count());

        $pendingShutdown = true;
        $reads = [];
        app()->setLocale('ar');
        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.terminate', $contractId), [
            'store_id' => $store->id,
        ])->assertForbidden();
        $this->assertFalse($pendingShutdown);
        // A suspended module is rejected by the HTTP access gate before the
        // mutation transaction. The re-enabled positive path proves lock order.
        $this->assertSame([], $reads);
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'active', 'terminated_by' => null,
        ]);
        $this->assertSame($beforeTerminate, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.terminated')->count());

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        $reads = [];
        app()->setLocale('en');
        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.terminate', $contractId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertSame(['module', 'contract'], array_slice(array_values(array_unique($reads)), 0, 2));
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contractId, 'status' => 'terminated', 'terminated_by' => $checker->id,
        ]);
        $this->assertSame($beforeTerminate + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.terminated')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.contracts.terminate', $contractId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertSame($beforeTerminate + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.contract.terminated')->count());
    }

    public function test_rfq_creation_rechecks_module_shutdown_after_preflight_without_draft_or_audit(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $reason = 'P135 RFQ create lifecycle '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'currency_code' => 'KWD', 'reason' => $reason,
            'item_code' => 'P135-RFQ-CREATE-LOCK', 'description' => 'Module shutdown ordering',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', $reason)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'submitted', 'store_id' => $store->id,
        ]);

        $createUrl = route('admin.erp.procurement.rfqs.store');
        $postData = [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 creation after preflight',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ];
        $before = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count();
        $pending = true;
        $insideLifecycleTransition = false;
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$pending, &$insideLifecycleTransition, &$reads): void {
            if ($insideLifecycleTransition) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            // Interleave in the same PHP process after the initial
            // module-enabled preflight, before draft RFQ creation begins.
            if ($pending && str_contains($query->sql, 'enterprise_modules')) {
                $pending = false;
                $insideLifecycleTransition = true;
                try {
                    app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
                } finally {
                    $insideLifecycleTransition = false;
                }
            }

            if (DB::connection()->transactionLevel() < 2) {
                return;
            }
            if (str_contains($query->sql, 'enterprise_modules')) {
                $reads[] = 'module';
            } elseif (str_contains($query->sql, 'enterprise_purchase_requests')) {
                $reads[] = 'request';
            }
        });

        app()->setLocale('ar');
        $this->actingAs($maker)->post($createUrl, $postData)->assertForbidden();
        $this->assertFalse($pending, 'The test must actually inject a post-preflight shutdown.');
        $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
        $this->assertSame([], $reads, 'Early authorization denial must not enter the RFQ write transaction.');
        $this->assertDatabaseMissing('enterprise_procurement_rfqs', [
            'purchase_request_id' => $requestId,
        ]);
        $this->assertSame($before, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count());
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'submitted', 'supplier_id' => null,
        ]);

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app()->setLocale('en');
        $maker->forceFill(['locale' => 'en'])->save();
        $reads = [];
        $this->actingAs($maker->fresh())->post($createUrl, $postData)->assertRedirect();
        $this->assertSame(['module', 'request'], array_slice(array_values(array_unique($reads)), 0, 2));
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $rfqId);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'draft', 'store_id' => $store->id,
        ]);
        $this->assertSame(1, DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfqId)->count());
        $this->assertSame($before + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count());

        // Existing request → RFQ idempotency must remain intact.
        $this->actingAs($maker->fresh())->post($createUrl, $postData)->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->count());
        $this->assertSame($before + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.created')->count());
    }

    public function test_rfq_cancel_after_module_shutdown_preflight_does_not_revoke_invitation_or_audit_then_recovers(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [$supplierId, $rfqId] = $this->draftRfqForPublish($maker, $store);

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'invited',
        ]);
        $auditBefore = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.cancelled')->count();

        $pendingShutdown = true;
        $insideLifecycleTransition = false;
        $lockedReadOrder = [];
        DB::listen(function (QueryExecuted $query) use (&$pendingShutdown, &$insideLifecycleTransition, &$lockedReadOrder): void {
            if ($insideLifecycleTransition) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            // Deliberate single-process preflight interleave, not two HTTP
            // workers. The scope() lookup already returned its ENABLED state
            // by the time this callback suspends the persisted lifecycle.
            if ($pendingShutdown && str_contains($query->sql, 'enterprise_modules')) {
                $pendingShutdown = false;
                $insideLifecycleTransition = true;
                try {
                    app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
                } finally {
                    $insideLifecycleTransition = false;
                }
            }

            if (DB::connection()->transactionLevel() < 2) {
                return;
            }
            if (str_contains($query->sql, 'enterprise_modules')) {
                $lockedReadOrder[] = 'module';
            } elseif (str_contains($query->sql, 'enterprise_procurement_rfqs')) {
                $lockedReadOrder[] = 'rfq';
            }
        });

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.cancel', $rfqId), [
            'store_id' => $store->id,
        ])->assertForbidden();
        $this->assertFalse($pendingShutdown);
        $this->assertSame([], $lockedReadOrder, 'Denied request must not lock or revoke an RFQ.');
        $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published', 'cancelled_by' => null,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'invited',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.cancelled')->count());

        // Recovery is a genuine explicitly authorized module lifecycle
        // change, not permission to bypass the disabled-module gate.
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app()->setLocale('en');
        $maker->forceFill(['locale' => 'en'])->save();
        $lockedReadOrder = [];
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.cancel', $rfqId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertSame(['module', 'rfq'], array_slice(array_values(array_unique($lockedReadOrder)), 0, 2));
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'cancelled', 'cancelled_by' => $maker->id,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'revoked',
        ]);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.cancelled')->count());

        // Idempotent retry must not revoke or audit twice.
        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.rfqs.cancel', $rfqId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.cancelled')->count());
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'revoked',
        ]);
    }

    public function test_rfq_publish_locks_module_rfq_and_scoped_supplier_before_issuing_one_invitation(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        [$supplierId, $rfqId] = $this->draftRfqForPublish($maker, $store);
        $auditBefore = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.published')->count();

        // RefreshDatabase has an outer test-owned transaction. This observes
        // only the nested publication transaction in this PHP process, not a
        // real independent PHP/HTTP worker contention or signed stage proof.
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (DB::connection()->transactionLevel() < 2
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            if (str_contains($query->sql, 'enterprise_modules')) {
                $reads[] = 'module';
            } elseif (str_contains($query->sql, 'enterprise_procurement_rfqs')) {
                $reads[] = 'rfq';
            } elseif (str_contains($query->sql, 'enterprise_suppliers')) {
                $reads[] = 'supplier';
            }
        });

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');

        $order = array_values(array_unique($reads));
        $this->assertSame(['module', 'rfq', 'supplier'], array_slice($order, 0, 3));
        $this->assertCount(1, session('procurement_portal_links'));
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
            'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'invited',
        ]);
        $this->assertSame(1, DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.published')->count());

        // A retried publish request must not mint another bearer invitation,
        // even if a stale browser submits its previous supplier selection.
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.published')->count());
    }

    public function test_rfq_publish_denies_module_shutdown_and_supplier_governance_after_preflight_without_tokens(): void
    {
        $store = $this->store();
        $foreignStore = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-PUBLISH-FOREIGN', 'name' => 'Foreign RFQ invitation store', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreignStore);

        $pending = null;
        $activeSupplier = null;
        $triggered = [];
        DB::listen(function (QueryExecuted $query) use (&$pending, &$activeSupplier, &$triggered, $foreignStore): void {
            if ($pending === null
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_modules')) {
                return;
            }

            // This is a deterministic single-process preflight interleave:
            // complete governance mutation before the publication locks run.
            $action = $pending;
            $pending = null;
            $triggered[] = $action;
            if ($action === 'suspend') {
                app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
            } else {
                DB::table('enterprise_suppliers')->where('id', $activeSupplier)->update(match ($action) {
                    'hold' => ['status' => 'on_hold'],
                    'disqualified' => ['qualification_status' => 'unqualified'],
                    'foreign_scope' => ['scope_type' => 'store', 'store_id' => $foreignStore->id],
                });
            }
        });

        foreach ([
            ['locale' => 'ar', 'action' => 'hold'],
            ['locale' => 'en', 'action' => 'disqualified'],
            ['locale' => 'ar', 'action' => 'foreign_scope'],
            ['locale' => 'en', 'action' => 'suspend'],
        ] as $case) {
            $maker = $this->actor('PROCUREMENT_BUYER', $case['locale']);
            [$supplierId, $rfqId] = $this->draftRfqForPublish($maker, $store);
            $beforeAudit = DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.published')->count();
            $activeSupplier = $supplierId;
            $pending = $case['action'];
            app()->setLocale($case['locale']);

            $result = $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
                'store_id' => $store->id, 'supplier_ids' => [$supplierId],
            ]);
            if ($case['action'] === 'suspend') {
                $result->assertForbidden();
                $this->assertSame(ModuleState::SUSPENDED, app(ModuleRegistry::class)->state('procurement'));
            } else {
                $result->assertRedirect()->assertSessionHasErrors('supplier_ids');
            }

            $this->assertNull($pending, 'Governance must change only after the module preflight SELECT.');
            $this->assertDatabaseHas('enterprise_procurement_rfqs', [
                'id' => $rfqId, 'status' => 'draft',
            ]);
            $this->assertSame(0, DB::table('enterprise_procurement_rfq_invitations')
                ->where('rfq_id', $rfqId)->count());
            $this->assertSame($beforeAudit, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.published')->count());
        }

        $this->assertSame(['hold', 'disqualified', 'foreign_scope', 'suspend'], $triggered);
    }

    public function test_authenticated_rfq_award_locks_procurement_before_rfq_and_rejects_replay_after_shutdown(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();

        // RefreshDatabase owns the outer transaction. This listener records
        // only SELECTs during awardRfq's nested transaction; it is NOT a
        // substitute for two independent HTTP workers on MySQL.
        $lockedReadOrder = [];
        DB::listen(function (QueryExecuted $query) use (&$lockedReadOrder): void {
            if (DB::connection()->transactionLevel() < 2
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            if (str_contains($query->sql, 'enterprise_modules')) {
                $lockedReadOrder[] = 'module';
            } elseif (str_contains($query->sql, 'enterprise_procurement_rfqs')) {
                $lockedReadOrder[] = 'rfq';
            }
        });

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();

        $this->assertNotEmpty($lockedReadOrder);
        $this->assertSame('module', $lockedReadOrder[0], 'Module row must be checked under the award transaction before RFQ.');
        $this->assertContains('rfq', $lockedReadOrder);
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());
        $awardAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();
        $this->assertGreaterThan(0, $awardAudits);

        // A subsequent operator shutdown is authoritative even for a
        // formerly successful, idempotent award URL. No second PO or audit.
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::SUSPENDED);
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertForbidden();

        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'awarded', 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());
        $this->assertSame($awardAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
    }

    public function test_award_rechecks_supplier_hold_and_disqualification_after_authorized_preflight_without_po(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();
        $beforeCost = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost');
        $foreignStore = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-AWARD-SUPPLIER-FOREIGN', 'name' => 'Foreign supplier scope', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreignStore);
        $injection = null;
        $injected = [];

        // Reproduce a governance change AFTER the module-enabled preflight
        // SELECT and BEFORE award's locked supplier eligibility read. This
        // QueryExecuted listener is a deterministic single-process interleave;
        // independent two-worker MySQL/HTTP acceptance remains separately due.
        DB::listen(function (QueryExecuted $query) use (&$injection, &$injected, $supplierId, $foreignStore): void {
            if ($injection === null
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_modules')) {
                return;
            }

            $kind = $injection;
            $injection = null;
            DB::table('enterprise_suppliers')->where('id', $supplierId)->update(
                match ($kind) {
                    'hold' => ['status' => 'on_hold'],
                    'disqualified' => ['qualification_status' => 'unqualified'],
                    'foreign_store' => ['scope_type' => 'store', 'store_id' => $foreignStore->id],
                },
            );
            $injected[] = $kind;
        });

        foreach (['ar' => 'hold', 'en' => 'disqualified'] as $locale => $kind) {
            DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
                'status' => 'active', 'qualification_status' => 'qualified',
            ]);
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $injection = $kind;

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
                'rfq' => $rfqId, 'quote' => $quoteId,
            ]), ['store_id' => $store->id])
                ->assertRedirect()->assertSessionHasErrors('supplier_id');

            $this->assertNull($injection, 'Supplier governance change must occur during module-enabled preflight.');
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
            ]);
            $this->assertUnawarded($requestId, $rfqId, $beforeCost);
            $this->assertSame($beforeAudits, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.awarded')->count());
        }

        // A supplier reassigned to a different B2B store cannot be awarded
        // through the original store's valid checker, RFQ and quote IDs.
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'status' => 'active', 'qualification_status' => 'qualified',
            'scope_type' => 'store', 'store_id' => $store->id,
        ]);
        $injection = 'foreign_store';
        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertNotFound();
        $this->assertNull($injection);
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplierId, 'scope_type' => 'store', 'store_id' => $foreignStore->id,
        ]);
        $this->assertUnawarded($requestId, $rfqId, $beforeCost);
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
        $this->assertSame(['hold', 'disqualified', 'foreign_store'], $injected);
    }

    public function test_rfq_cannot_award_before_independent_checker_approves_without_mutating_supplier_or_price(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);
        $this->assertGreaterThan(0, $supplierId);

        // An RFQ and an authentic, submitted supplier quote do not constitute
        // approval. Even a valid independent checker must approve the PR first.
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
                'rfq' => $rfqId, 'quote' => $quoteId,
            ]), ['store_id' => $store->id])
                ->assertRedirect()->assertSessionHasErrors('purchase_request_id');
        }

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'store_id' => $store->id,
            'status' => 'submitted', 'supplier_id' => null, 'decided_by' => null,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published', 'awarded_quote_id' => null,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'supplier_id' => $supplierId, 'status' => 'submitted',
        ]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertSame('5.000', number_format((float) DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost'), 3, '.', ''));
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
    }

    public function test_award_rejects_invalidated_submitted_invitation_in_both_locales_without_po_or_audit(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)
            ->where('supplier_id', $supplierId)
            ->first();
        $this->assertNotNull($invitation);
        $this->assertSame('submitted', $invitation->status);
        $originalCost = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost');
        $awardAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();

        // A quote that was genuinely submitted before revocation remains
        // persisted, but is no longer authority to create a PO. Reject even
        // though module, RFQ, buyer/checker and supplier are still eligible.
        foreach ([
            ['locale' => 'ar', 'status' => 'revoked'],
            ['locale' => 'en', 'status' => 'expired'],
            ['locale' => 'ar', 'status' => 'opened'],
        ] as $case) {
            $locale = $case['locale'];
            $status = $case['status'];
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $invitation->id)
                ->update(['status' => $status]);
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
                'rfq' => $rfqId, 'quote' => $quoteId,
            ]), ['store_id' => $store->id])
                ->assertRedirect()->assertSessionHasErrors('quote_id');

            $this->assertDatabaseHas('enterprise_procurement_rfq_invitations', [
                'id' => $invitation->id, 'status' => $status,
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'status' => 'submitted', 'total_amount' => '8.500',
            ]);
            $this->assertUnawarded($requestId, $rfqId, $originalCost);
            $this->assertSame($awardAudits, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.awarded')->count());
        }
    }

    public function test_tampered_persisted_supplier_quote_negative_money_or_quantity_cannot_create_po(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'approved', 'decided_by' => $checker->id,
            'supplier_id' => null,
        ]);

        // A corrupted import / forged persisted quote can make its line total
        // agree with its header yet still violate nonnegative money or the
        // authoritative RFQ quantity. Never trust header-total parity alone.
        $originalLine = DB::table('enterprise_procurement_quote_lines')
            ->where('quote_id', $quoteId)->first();
        $this->assertNotNull($originalLine);
        $awardAuditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();

        foreach ([
            ['locale' => 'ar', 'quantity' => '2.000', 'unit_price' => '-4.250',
                'line_total' => '-8.500', 'total_amount' => '-8.500'],
            ['locale' => 'en', 'quantity' => '3.000', 'unit_price' => '4.250',
                'line_total' => '12.750', 'total_amount' => '12.750'],
        ] as $tamper) {
            app()->setLocale($tamper['locale']);
            $checker->forceFill(['locale' => $tamper['locale']])->save();
            DB::table('enterprise_procurement_quote_lines')->where('id', $originalLine->id)->update([
                'quantity' => $tamper['quantity'],
                'unit_price' => $tamper['unit_price'],
                'line_total' => $tamper['line_total'],
            ]);
            DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
                ->update(['total_amount' => $tamper['total_amount']]);

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
                'rfq' => $rfqId, 'quote' => $quoteId,
            ]), ['store_id' => $store->id])
                ->assertRedirect()->assertSessionHasErrors('quote_id');

            $this->assertUnawarded($requestId, $rfqId, '5.000');
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'rfq_id' => $rfqId,
                'supplier_id' => $supplierId, 'status' => 'submitted',
            ]);
            $this->assertSame($awardAuditCount, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.awarded')->count());
            $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        }
    }

    public function test_awarded_rfq_without_matching_purchase_order_fails_closed_instead_of_returning_phantom_id(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();

        // Emulate a recovered/inconsistent legacy award without its PO.
        // This must never generate a success link to an invented order 0.
        DB::table('enterprise_procurement_rfqs')->where('id', $rfqId)->update([
            'status' => 'awarded',
            'awarded_quote_id' => $quoteId,
            'awarded_by' => $checker->id,
            'awarded_at' => now(),
        ]);
        $this->assertDatabaseMissing('enterprise_purchase_orders', ['procurement_rfq_id' => $rfqId]);
        $beforeAwardAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertStatus(409);

        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'awarded', 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertDatabaseMissing('enterprise_purchase_orders', ['procurement_rfq_id' => $rfqId]);
        $this->assertSame($beforeAwardAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
    }

    public function test_rfq_award_cannot_rewrite_an_existing_issued_purchase_order_or_its_line_prices(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();

        // An already-approved, supplier-qualified request can independently
        // produce a PO. Keep this a genuine HTTP create/issue journey.
        DB::table('enterprise_purchase_requests')->where('id', $requestId)
            ->update(['supplier_id' => $supplierId]);
        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.create', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')
            ->where('purchase_request_id', $requestId)->first();
        $this->assertNotNull($order);

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'supplier_id' => $supplierId,
            'procurement_rfq_id' => null, 'awarded_quote_id' => null,
        ]);
        $beforeAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertStatus(409);

        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published', 'awarded_quote_id' => null,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'supplier_id' => $supplierId,
            'procurement_rfq_id' => null, 'awarded_quote_id' => null,
        ]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 1);
        $this->assertSame('5.000', number_format((float) DB::table('enterprise_purchase_order_lines')
            ->where('purchase_order_id', $order->id)->value('unit_price'), 3, '.', ''));
        $this->assertSame('5.000', number_format((float) DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost'), 3, '.', ''));
        $this->assertSame($beforeAudits, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());
    }

    public function test_another_wholesale_rfqs_valid_quote_cannot_be_replayed_into_the_approved_request(): void
    {
        $store = $this->store();
        $otherStore = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-RFQ-REPLAY-FOREIGN',
            'name' => 'Foreign Wholesale RFQ scope',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($otherStore);

        $firstMaker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $foreignMaker = $this->actor('PROCUREMENT_BUYER', 'en');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');

        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($firstMaker, $store);
        [$foreignSupplierId, , $foreignRfqId, $foreignQuoteId] = $this->quotedRequest($foreignMaker, $otherStore);
        $this->assertNotSame($rfqId, $foreignRfqId);
        $this->assertNotSame($quoteId, $foreignQuoteId);
        $this->assertNotSame($supplierId, $foreignSupplierId);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertRedirect();

        $beforeAwardAudits = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();
        $originalRequestPrice = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();

            // This is a genuine submitted quote, not a made-up identifier.
            // It belongs to a different RFQ/store and is never legal authority
            // to award the otherwise approved purchase request in this store.
            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.rfqs.award', [
                'rfq' => $rfqId,
                'quote' => $foreignQuoteId,
            ]), ['store_id' => $store->id])->assertNotFound();

            $this->assertUnawarded($requestId, $rfqId, $originalRequestPrice);
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $quoteId, 'rfq_id' => $rfqId,
                'supplier_id' => $supplierId, 'status' => 'submitted',
            ]);
            $this->assertDatabaseHas('enterprise_procurement_quotes', [
                'id' => $foreignQuoteId, 'rfq_id' => $foreignRfqId,
                'supplier_id' => $foreignSupplierId, 'status' => 'submitted',
            ]);
            $this->assertSame($beforeAwardAudits, DB::table('audit_logs')
                ->where('event', 'enterprise.procurement.rfq.awarded')->count());
        }

        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
    }

    public function test_expired_or_forged_quote_fails_http_before_award_mutates_request_supplier_or_amount(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $url = route('admin.erp.procurement.rfqs.award', ['rfq' => $rfqId, 'quote' => $quoteId]);
        $beforePrice = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost');

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['valid_until' => now()->addDays(5)->toDateString(), 'total_amount' => 999999]);
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['total_amount' => '8.500']);
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.archive', $supplierId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('supplier_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);
    }

    public function test_self_approval_and_cross_store_supplier_or_negative_amount_never_persist_forged_procurement(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'submitted',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertUnawarded($requestId, $rfqId, '5.000');

        $other = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-RFQ-OTHER', 'name' => 'Other Wholesale scope', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($other);
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $other->id, 'scope_type' => 'store',
            'code' => 'P135-RFQ-FOREIGN', 'name_en' => 'Foreign supplier',
            'name_ar' => 'مورد نطاق آخر',
        ])->assertRedirect();
        $foreignSupplierId = (int) DB::table('enterprise_suppliers')
            ->where('code', 'P135-RFQ-FOREIGN')->value('id');
        $this->assertGreaterThan(0, $foreignSupplierId);
        $before = DB::table('enterprise_purchase_requests')->count();
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'supplier_id' => $foreignSupplierId,
            'currency_code' => 'KWD', 'reason' => 'Forged scope',
            'item_code' => 'P135-RFQ-SKU', 'description' => 'Foreign supplier',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => 5,
        ])->assertNotFound();
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'supplier_id' => $supplierId,
            'currency_code' => 'KWD', 'reason' => 'Forged negative amount',
            'item_code' => 'P135-RFQ-SKU', 'description' => 'Negative price',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => -10,
        ])->assertRedirect()->assertSessionHasErrors('estimated_unit_cost');
        $this->assertSame($before, DB::table('enterprise_purchase_requests')->count());
    }

    /**
     * An authentic, unpublished maker RFQ and company/store-scoped supplier.
     *
     * @return array{int,int}
     */
    private function draftRfqForPublish(User $maker, Store $store): array
    {
        $code = 'P135-PUBLISH-SUP-'.$maker->id;
        $reason = 'P135 publish preflight '.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store', 'code' => $code,
            'name_en' => 'P135 invitation publication supplier',
            'name_ar' => 'مورد اختبار نشر الدعوة',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'currency_code' => 'KWD', 'reason' => $reason,
            'item_code' => 'P135-PUBLISH-SKU', 'description' => 'RFQ publication guard',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '4.250',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', $reason)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 unpublished invitation '.$maker->id,
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $rfqId);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'draft', 'store_id' => $store->id,
        ]);
        $this->assertSame(0, DB::table('enterprise_procurement_rfq_invitations')
            ->where('rfq_id', $rfqId)->count());

        return [$supplierId, $rfqId];
    }

    /** @return array{int,int,int,int} */
    private function quotedRequest(User $maker, Store $store, bool $assertPricePrecision = false): array
    {
        $code = 'P135-RFQ-SUP-'.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store', 'code' => $code,
            'name_en' => 'P135 RFQ supplier', 'name_ar' => 'مورد عرض سعر موثق',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD', 'reason' => 'P135 source chain '.$maker->id,
            'item_code' => 'P135-RFQ-SKU', 'description' => 'P135 traceable item',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'P135 source chain '.$maker->id)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 independent supplier quote',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $rfqId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');
        $links = session('procurement_portal_links');
        $this->assertCount(1, $links);
        $token = basename((string) parse_url((string) $links[0]['url'], PHP_URL_PATH));
        $this->assertSame(64, strlen($token));
        $rfqLineId = (int) DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfqId)->value('id');

        if ($assertPricePrecision) {
            // Real supplier-token HTTP contract in BOTH locales, before any
            // quote exists: sub-mill values cannot be silently rounded into
            // stored quote lines or an awardable supplier commitment.
            foreach (['ar' => '4.2509', 'en' => '4.25001'] as $locale => $invalidPrice) {
                app()->setLocale($locale);
                $this->post(route('supplier.rfq.submit', ['token' => $token]), [
                    'valid_until' => now()->addDays(10)->toDateString(),
                    'lines' => [[
                        'rfq_line_id' => $rfqLineId, 'unit_price' => $invalidPrice,
                    ]],
                ])->assertRedirect()->assertSessionHasErrors('lines.0.unit_price');

                $this->assertSame(0, DB::table('enterprise_procurement_quotes')
                    ->where('rfq_id', $rfqId)->count());
                $this->assertSame(0, DB::table('enterprise_procurement_quote_lines')
                    ->where('rfq_line_id', $rfqLineId)->count());
            }
            app()->setLocale('ar');
        }

        // Forged supplier and total_amount in HTTP body must never override
        // the invitation-bound supplier or service-computed quote total.
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'supplier_id' => 999999, 'total_amount' => 999999999,
            'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [[
                'rfq_line_id' => $rfqLineId, 'unit_price' => '4.250',
                'lead_time_days' => 3,
            ]],
        ])->assertRedirect();
        $quoteId = (int) DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfqId)->value('id');
        $this->assertGreaterThan(0, $quoteId);

        return [$supplierId, $requestId, $rfqId, $quoteId, $token];
    }

    private function assertUnawarded(int $requestId, int $rfqId, mixed $beforePrice): void
    {
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'supplier_id' => null, 'status' => 'approved',
        ]);
        $this->assertSame(
            number_format((float) $beforePrice, 3, '.', ''),
            number_format((float) DB::table('enterprise_purchase_request_lines')
                ->where('purchase_request_id', $requestId)->value('estimated_unit_cost'), 3, '.', ''),
        );
        $this->assertDatabaseMissing('enterprise_purchase_orders', ['procurement_rfq_id' => $rfqId]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    private function actor(string $code, string $locale): User
    {
        $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function store(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store): array
    {
        $sku = 'P135-RFQ-SKU';
        $catalog = Catalog::query()->create([
            'store_id' => $store->id, 'channel' => 'b2c',
            'code' => 'p135-rfq-catalog', 'name' => 'P135 RFQ stock',
            'is_active' => true, 'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null, 'scope' => 'global', 'scope_key' => 'global',
            'code' => 'P135-RFQ-EACH', 'name' => 'Each', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'catalog_id' => $catalog->id, 'unit_id' => $unitId,
            'sku' => $sku, 'name' => $sku, 'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id, 'code' => 'P135-RFQ-WAREHOUSE',
            'name' => 'P135 RFQ warehouse', 'is_active' => true,
        ]);

        return [$product, $warehouse];
    }
}
