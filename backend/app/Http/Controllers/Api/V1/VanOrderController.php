<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\B2bPriceResolver;
use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Order;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\AdminOrderManagementService;
use App\Services\ProductAvailabilityService;
use App\Services\VanOrderReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VanOrderController extends Controller
{
    public function __construct(
        private readonly AdminOrderManagementService $orders,
        private readonly ProductAvailabilityService $availability,
        private readonly B2bPriceResolver $b2bPrices,
        private readonly VanOrderReadService $read,
    ) {}

    public function catalog(Request $request, string $type, int $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $storeId = (int) $validated['store_id'];
        $this->assertCustomerScope($actor, $type, $customer, $storeId);
        $search = trim((string) ($validated['q'] ?? ''));

        $rows = $type === 'b2b'
            ? $this->b2bCatalog($customer, $storeId, $search)
            : $this->b2cCatalog($customer, $storeId, $search);

        return response()->json([
            'data' => $rows,
            'currency' => 'EGP',
        ]);
    }

    public function options(Request $request, string $type, int $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store_id'];
        $this->assertCustomerScope($actor, $type, $customer, $storeId);

        $customerColumn = $type === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $addresses = DB::table('addresses')
            ->where($customerColumn, $customer)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get([
                'id',
                'label',
                'line1',
                'line2',
                'area',
                'city',
                'latitude',
                'longitude',
                'is_default',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'label' => trim((string) ($row->label ?? '')),
                'address' => collect([$row->line1, $row->line2, $row->area, $row->city])
                    ->map(static fn (mixed $value): string => trim((string) $value))
                    ->filter(static fn (string $value): bool => $value !== '')
                    ->implode(' · '),
                'latitude' => $row->latitude === null ? null : (float) $row->latitude,
                'longitude' => $row->longitude === null ? null : (float) $row->longitude,
                'is_default' => (bool) $row->is_default,
            ])
            ->values();

        $warehouses = $type === 'b2b'
            ? DB::table('warehouses')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ])
                ->values()
            : collect();

        $paymentMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if ($type === 'b2b') {
            $account = B2bAccount::query()
                ->where('b2b_customer_id', $customer)
                ->where('status', 'active')
                ->first();
            if (
                $account instanceof B2bAccount
                && (float) $account->credit_limit > 0
                && ! in_array('account_credit', $paymentMethods, true)
            ) {
                $paymentMethods[] = 'account_credit';
            }
        }

        return response()->json([
            'data' => [
                'addresses' => $addresses,
                'warehouses' => $warehouses,
                'payment_methods' => $paymentMethods,
                'default_payment_method' => (string) config(
                    'checkout.default_payment_method',
                    'cash_on_delivery',
                ),
            ],
        ]);
    }

    public function quote(Request $request, string $type, int $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertCustomerScope($actor, $type, $customer, $storeId);

        $request->merge(['customer_id' => $customer]);
        $quote = $this->orders->quote($request, $type, $storeId);

        return response()->json(['data' => $quote]);
    }

    public function store(Request $request, string $type, int $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertCustomerScope($actor, $type, $customer, $storeId);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A valid Idempotency-Key header is required.'],
            ]);
        }

        $request->merge(['customer_id' => $customer]);
        $order = $this->orders->create(
            $request,
            $actor,
            $type,
            $storeId,
            'van',
            'van',
            $idempotencyKey,
        );

        return response()->json(['data' => $this->orderPayload($order)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:80'],
            'customer_type' => ['nullable', Rule::in(['b2b'])],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->read->queryForActor($actor)
            ->when(
                isset($validated['status']),
                fn ($orderQuery) => $orderQuery->where('orders.status', $validated['status']),
            )
            ->when(
                isset($validated['customer_id']),
                fn ($orderQuery) => $orderQuery->where(
                    'orders.b2b_customer_id',
                    (int) $validated['customer_id'],
                ),
            );

        $page = $query
            ->latest('orders.id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Order $order): array => $this->read->summary($order))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'scope' => 'active_van_assignment',
            ],
        ]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'max:16'],
        ]);
        $model = $this->read->findOwned($actor, $order);

        if (isset($validated['store_id'])) {
            abort_unless((int) $validated['store_id'] === (int) $model->store_id, 404);
        }

        if (isset($validated['channel'])) {
            abort_unless(
                strtolower(trim((string) $validated['channel'])) === 'b2b',
                404,
            );
        }

        return response()->json([
            'data' => $this->read->detail($model),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertCustomerScope(
        User $actor,
        string $type,
        int $customer,
        int $storeId,
    ): void {
        abort_unless(in_array($type, ['b2b', 'b2c'], true), 404);

        abort_unless(
            VanVisit::query()
                ->where('actor_user_id', $actor->getKey())
                ->where('customer_type', $type)
                ->where('customer_id', $customer)
                ->where('store_id', $storeId)
                ->exists(),
            404,
        );
    }

    /** @return list<array<string, mixed>> */
    private function b2bCatalog(int $customer, int $storeId, string $search): array
    {
        $b2bCustomer = B2bCustomer::query()->find($customer);
        abort_unless($b2bCustomer instanceof B2bCustomer, 404);

        $rows = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($nested) use ($like): void {
                    $nested->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like);
                });
            })
            ->orderBy('products.name')
            ->limit(300)
            ->get([
                'products.id',
                'products.name',
                'products.sku',
                'products.barcode',
            ]);

        $pricing = $this->b2bPrices->resolveMany(
            $b2bCustomer,
            $rows
                ->map(static fn (object $row): array => [
                    'store_id' => $storeId,
                    'product_id' => (int) $row->id,
                ])
                ->values()
                ->all(),
        );

        return $rows
            ->filter(static fn (object $row): bool => isset($pricing[$storeId.':'.(int) $row->id]))
            ->map(function (object $row) use ($pricing, $storeId): array {
                $price = $pricing[$storeId.':'.(int) $row->id];

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'sku' => (string) $row->sku,
                    'barcode' => $row->barcode,
                    'unit_price' => (float) $price['price'],
                    'minimum_quantity' => (float) $price['minimum_quantity'],
                    'ordering_increment' => (float) $price['ordering_increment'],
                    ...$this->availability->forStoreProduct($storeId, (int) $row->id),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function b2cCatalog(int $customer, int $storeId, string $search): array
    {
        abort_unless(
            B2cCustomer::query()->whereKey($customer)->where('store_id', $storeId)->exists(),
            404,
        );

        return DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($nested) use ($like): void {
                    $nested->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like);
                });
            })
            ->orderBy('products.name')
            ->limit(300)
            ->get([
                'products.id',
                'products.name',
                'products.sku',
                'products.barcode',
                'store_products.price as unit_price',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'barcode' => $row->barcode,
                'unit_price' => (float) ($row->unit_price ?? 0),
                'minimum_quantity' => 1.0,
                'ordering_increment' => 1.0,
                ...$this->availability->forStoreProduct($storeId, (int) $row->id),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function orderPayload(Order $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'customer_type' => $order->b2b_customer_id !== null ? 'b2b' : 'b2c',
            'customer_id' => (int) ($order->b2b_customer_id ?? $order->b2c_customer_id),
            'store_id' => (int) $order->store_id,
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'grand_total' => (float) $order->grand_total,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
