<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommerceQuoteService;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SalesQuotationController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly CommerceQuoteService $pricing,
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        [$actor, $storeId, $channel] = $this->authorizedStore($request, 'orders.view');

        return $this->workspace($actor, $storeId, $channel);
    }

    public function show(Request $request, int $quotation): View
    {
        $actor = $this->actor($request);
        $record = DB::table('sales_quotations')->where('id', $quotation)->first();
        abort_if($record === null, 404);
        $storeId = (int) $record->store_id;
        $channel = $this->scope->assertStore($actor, $storeId, 'orders.view');
        abort_unless($channel === $record->channel, 404);

        return $this->workspace($actor, $storeId, $channel, $record);
    }

    public function store(Request $request): RedirectResponse
    {
        [$actor, $storeId, $channel] = $this->authorizedStore($request, 'orders.manage', false);
        // Quotation creation requires the existing scoped operational manage grant;
        // it is not an order creation and grants no checkout/dispatch authority.
        $this->scope->assertStore($actor, $storeId, 'orders.view', $channel);

        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'expires_at' => ['required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.today()->addDays(90)->toDateString()],
        ]);

        // A retry of an already accepted form has one durable outcome, even
        // if catalog/pricing changes between requests.
        $existingId = DB::table('sales_quotations')
            ->where('store_id', $storeId)->where('created_by', $actor->getKey())
            ->where('request_id', $data['request_id'])->value('id');
        if ($existingId !== null) {
            return redirect()->route('admin.sales-quotations.show', ['quotation' => $existingId]);
        }

        $customer = $this->customer($channel, $storeId, (int) $data['customer_id']);
        $items = array_map(static fn (array $line): array => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (float) $line['quantity'],
        ], $data['items']);

        // The Dashboard never accepts client-provided prices or totals. The exact
        // Customer pricing engine owns MOQ, tiers, availability, promotions and tax.
        // Shipping is excluded from this merchandise quotation, not silently sold.
        $quote = $this->pricing->quote(
            $channel,
            $storeId,
            $customer,
            $items,
            deliveryTotalOverride: 0.0,
        );
        abort_if((bool) ($quote['has_unavailable_items'] ?? true), 422);

        try {
            $id = DB::transaction(function () use ($actor, $storeId, $channel, $customer, $quote, $data, $request): int {
                $reference = (string) Str::uuid();
                $id = (int) DB::table('sales_quotations')->insertGetId([
                    'reference' => $reference,
                    'request_id' => $data['request_id'],
                    'store_id' => $storeId,
                    'channel' => $channel,
                    'customer_id' => (int) $customer->getKey(),
                    'created_by' => $actor->getKey(),
                    'status' => 'draft',
                    'currency' => (string) $quote['currency'],
                    'subtotal' => $quote['subtotal'],
                    'discount_total' => $quote['discount_total'],
                    'tax_total' => $quote['tax_total'],
                    'grand_total' => $quote['grand_total'],
                    'pricing_snapshot' => json_encode($quote, JSON_THROW_ON_ERROR),
                    'expires_at' => $data['expires_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
    
                $this->audit->record('sales.quotation.created', $actor, null, null, [
                    'quotation_id' => $id,
                    'reference' => $reference,
                    'store_id' => $storeId,
                    'channel' => $channel,
                    'status' => 'draft',
                    'grand_total' => $quote['grand_total'],
                ], $request);
    
                return $id;
            }, 3);
        } catch (QueryException $exception) {
            // The unique request ID serializes concurrent retries. Do not
            // swallow any unrelated SQL error or emit a second audit entry.
            $id = DB::table('sales_quotations')
                ->where('store_id', $storeId)->where('created_by', $actor->getKey())
                ->where('request_id', $data['request_id'])->value('id');
            if ($id === null) {
                throw $exception;
            }
        }

        return redirect()->route('admin.sales-quotations.show', ['quotation' => $id])
            ->with('status', __('sales_quotations.created'));
    }

    public function transition(Request $request, int $quotation, string $action): RedirectResponse
    {
        abort_unless(in_array($action, ['issue', 'cancel'], true), 404);
        $actor = $this->actor($request);

        DB::transaction(function () use ($request, $actor, $quotation, $action): void {
            $record = DB::table('sales_quotations')->where('id', $quotation)->lockForUpdate()->first();
            abort_if($record === null, 404);
            $storeId = (int) $record->store_id;
            $channel = $this->scope->assertStore($actor, $storeId, 'orders.manage');
            $this->scope->assertStore($actor, $storeId, 'orders.view', $channel);
            abort_unless($channel === $record->channel, 404);

            if ($record->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => [__('sales_quotations.no_transition')],
                ]);
            }
            if ($action === 'issue') {
                if (today()->greaterThan((string) $record->expires_at)) {
                    throw ValidationException::withMessages([
                        'expires_at' => [__('sales_quotations.expired')],
                    ]);
                }
                $original = json_decode((string) $record->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $this->assertPriceUnchanged($record, $original);
            }

            $status = $action === 'issue' ? 'issued' : 'cancelled';
            $changes = ['status' => $status, 'updated_at' => now()];
            $changes[$action === 'issue' ? 'issued_at' : 'cancelled_at'] = now();
            if ($action === 'issue') {
                $changes['issued_by'] = $actor->getKey();
            }
            DB::table('sales_quotations')->where('id', $quotation)->update($changes);
            $this->audit->record('sales.quotation.'.$status, $actor, null, [
                'quotation_id' => $quotation,
                'store_id' => $storeId,
                'status' => 'draft',
            ], [
                'quotation_id' => $quotation,
                'store_id' => $storeId,
                'status' => $status,
            ], $request);
        }, 3);

        return redirect()->route('admin.sales-quotations.show', ['quotation' => $quotation])
            ->with('status', __('sales_quotations.updated'));
    }

    /** @param array<string, mixed> $original */
    private function assertPriceUnchanged(object $record, array $original): void
    {
        $channel = (string) $record->channel;
        $storeId = (int) $record->store_id;
        $customer = $this->customer($channel, $storeId, (int) $record->customer_id);
        $items = collect($original['items'] ?? [])->map(static fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (float) $item['quantity'],
        ])->all();
        if ($items === []) {
            throw ValidationException::withMessages(['items' => [__('sales_quotations.price_changed')]]);
        }

        $fresh = $this->pricing->quote(
            $channel,
            $storeId,
            $customer,
            $items,
            deliveryTotalOverride: 0.0,
        );
        $sameLines = count($fresh['items']) === count($original['items'] ?? [])
            && collect($fresh['items'])->every(static function (array $line, int $index) use ($original): bool {
                $prior = $original['items'][$index] ?? [];

                return (int) $line['product_id'] === (int) ($prior['product_id'] ?? 0)
                    && abs((float) $line['quantity'] - (float) ($prior['quantity'] ?? 0)) < 0.0005
                    && abs((float) $line['unit_price'] - (float) ($prior['unit_price'] ?? 0)) < 0.0005;
            });

        if (! $sameLines || abs((float) $fresh['grand_total'] - (float) $record->grand_total) >= 0.0005) {
            throw ValidationException::withMessages([
                'items' => [__('sales_quotations.price_changed')],
            ]);
        }
    }

    private function customer(string $channel, int $storeId, int $customerId): B2bCustomer|B2cCustomer
    {
        if ($channel === 'b2b') {
            return B2bCustomer::query()->findOrFail($customerId);
        }

        return B2cCustomer::query()->where('store_id', $storeId)->findOrFail($customerId);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }

    /** @return array{User,int,string} */
    private function authorizedStore(Request $request, string $permission, bool $allowDefault = true): array
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        if ($storeId <= 0 && $allowDefault) {
            $storeId = (int) ($this->scope->allowedStoreIds($actor, $permission)[0] ?? 0);
        }
        // Distinguish missing ability (403) from an unauthorized store ID
        // (404), without disclosing a foreign record to store-scoped actors.
        $authorizedStores = $this->scope->allowedStoreIds($actor, $permission);
        abort_if($authorizedStores === [], 403);
        abort_unless($storeId > 0, 404);
        $channel = $this->scope->assertStore($actor, $storeId, $permission);

        return [$actor, $storeId, $channel];
    }

    private function workspace(User $actor, int $storeId, string $channel, ?object $selected = null): View
    {
        $quotations = DB::table('sales_quotations')
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(20);

        $canCreate = in_array($storeId, $this->scope->allowedStoreIds($actor, 'orders.manage', $channel), true);
        $canManage = in_array($storeId, $this->scope->allowedStoreIds($actor, 'orders.manage', $channel), true);

        $customers = $channel === 'b2b'
            ? B2bCustomer::query()->orderBy('name')->limit(300)->get(['id', 'name'])
            : B2cCustomer::query()->where('store_id', $storeId)->orderBy('name')->limit(300)->get(['id', 'name']);

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->limit(300)
            ->get(['products.id', 'products.name', 'products.sku']);

        return view('admin.sales-quotations', [
            'user' => $actor,
            'storeId' => $storeId,
            'channel' => $channel,
            'stores' => DB::table('stores')
                ->whereIn('id', $this->scope->allowedStoreIds($actor, 'orders.view'))
                ->orderBy('name')->get(['id', 'name']),
            'quotations' => $quotations,
            'selected' => $selected,
            'selectedCustomerName' => $selected === null ? null
                : $this->customer($channel, $storeId, (int) $selected->customer_id)->name,
            'selectedLines' => $selected === null ? [] : (json_decode((string) $selected->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR)['items'] ?? []),
            'customers' => $customers,
            'products' => $products,
            'canCreate' => $canCreate,
            'canManage' => $canManage,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'sales_quotations',
        ]);
    }
}
