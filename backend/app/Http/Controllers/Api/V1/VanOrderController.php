<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2cCustomer;
use App\Models\Order;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\AdminOrderManagementService;
use App\Services\ProductAvailabilityService;
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
            'currency' => 'KWD',
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
            'customer_type' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scopes = VanVisit::query()
            ->where('actor_user_id', $actor->getKey())
            ->get(['customer_type', 'customer_id'])
            ->map(static fn (VanVisit $visit): string =>
                $visit->customer_type.':'.$visit->customer_id)
            ->unique()
            ->values();

        $b2bIds = $scopes
            ->filter(static fn (string $value): bool => str_starts_with($value, 'b2b:'))
            ->map(static fn (string $value): int => (int) substr($value, 4))
            ->values();
        $b2cIds = $scopes
            ->filter(static fn (string $value): bool => str_starts_with($value, 'b2c:'))
            ->map(static fn (string $value): int => (int) substr($value, 4))
            ->values();

        $query = Order::query()
            ->where(function ($scope) use ($b2bIds, $b2cIds): void {
                if ($b2bIds->isNotEmpty()) {
                    $scope->whereIn('b2b_customer_id', $b2bIds);
                }
                if ($b2cIds->isNotEmpty()) {
                    if ($b2bIds->isNotEmpty()) {
                        $scope->orWhereIn('b2c_customer_id', $b2cIds);
                    } else {
                        $scope->whereIn('b2c_customer_id', $b2cIds);
                    }
                }
                if ($b2bIds->isEmpty() && $b2cIds->isEmpty()) {
                    $scope->whereRaw('1 = 0');
                }
            })
            ->when(
                isset($validated['status']),
                fn ($orderQuery) => $orderQuery->where('status', $validated['status']),
            );

        if (isset($validated['customer_type'], $validated['customer_id'])) {
            $type = (string) $validated['customer_type'];
            $id = (int) $validated['customer_id'];
            abort_unless($scopes->contains($type.':'.$id), 404);
            $query->where($type === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id', $id);
        }

        $page = $query
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Order $order): array => $this->orderPayload($order))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
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
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer)
            ->where('status', 'active')
            ->first();
        abort_unless(
            $account instanceof B2bAccount && $account->price_tier_id !== null,
            403,
            'Approved B2B pricing account is required.',
        );

        return DB::table('b2b_price_rules')
            ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'b2b_price_rules.store_id');
            })
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.store_id', $storeId)
            ->where('b2b_price_rules.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
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
                'b2b_price_rules.unit_price',
                'b2b_price_rules.minimum_quantity',
                'b2b_price_rules.ordering_increment',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'barcode' => $row->barcode,
                'unit_price' => (float) $row->unit_price,
                'minimum_quantity' => (float) $row->minimum_quantity,
                'ordering_increment' => (float) $row->ordering_increment,
                ...$this->availability->forStoreProduct($storeId, (int) $row->id),
            ])
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
