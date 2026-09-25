<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Models\CustomerFavorite;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomerProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $customer = Customer::query()
            ->where('user_id', $user->getKey())
            ->first();

        return response()->json($this->profilePayload($user, $customer));
    }

    public function update(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $customer] = $this->context($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);

        $before = [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'locale' => $user->locale,
        ];

        DB::transaction(function () use ($user, $customer, $validated): void {
            if (array_key_exists('name', $validated)) {
                $name = trim((string) $validated['name']);
                $user->name = $name;
                $customer->name = $name;
            }

            if (array_key_exists('email', $validated)) {
                $email = Str::lower(trim((string) $validated['email']));
                $user->email = $email;
                $customer->email = $email;
            }

            if (array_key_exists('phone', $validated)) {
                $customer->phone = $validated['phone'] === null
                    ? null
                    : trim((string) $validated['phone']);
            }

            if (array_key_exists('locale', $validated)) {
                $user->locale = (string) $validated['locale'];
            }

            $user->save();
            $customer->save();
        });

        $user->refresh();
        $customer->refresh();

        $auditLogger->record(
            'customer.profile_updated',
            $user,
            $customer,
            $before,
            [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'locale' => $user->locale,
            ],
            $request,
        );

        return response()->json($this->profilePayload($user, $customer));
    }

    public function addresses(Request $request): JsonResponse
    {
        [, $customer] = $this->context($request);

        return response()->json([
            'data' => Address::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (Address $address): array => $this->addressPayload($address))
                ->values()
                ->all(),
        ]);
    }

    public function storeAddress(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $customer] = $this->context($request);

        $validated = $request->validate($this->addressRules(false));

        $address = DB::transaction(function () use ($customer, $validated): Address {
            $shouldDefault = (bool) ($validated['is_default'] ?? false)
                || ! Address::query()->where('customer_id', $customer->getKey())->exists();

            if ($shouldDefault) {
                Address::query()
                    ->where('customer_id', $customer->getKey())
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            return Address::query()->create([
                'customer_id' => $customer->getKey(),
                ...$this->normalizedAddressValues($validated),
                'is_default' => $shouldDefault,
            ]);
        });

        $auditLogger->record(
            'customer.address_created',
            $user,
            $address,
            null,
            $this->addressPayload($address),
            $request,
        );

        return response()->json($this->addressPayload($address), 201);
    }

    public function updateAddress(
        Request $request,
        int $address,
        AuditLogger $auditLogger,
    ): JsonResponse {
        [$user, $customer] = $this->context($request);

        $model = Address::query()
            ->whereKey($address)
            ->where('customer_id', $customer->getKey())
            ->firstOrFail();

        $validated = $request->validate($this->addressRules(true));
        $before = $this->addressPayload($model);

        DB::transaction(function () use ($model, $customer, $validated): void {
            if (($validated['is_default'] ?? false) === true) {
                Address::query()
                    ->where('customer_id', $customer->getKey())
                    ->whereKeyNot($model->getKey())
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $values = $this->normalizedAddressValues($validated);

            if ($values !== []) {
                $model->fill($values);
            }

            if (array_key_exists('is_default', $validated)) {
                $model->is_default = (bool) $validated['is_default'];
            }

            $model->save();

            if (! Address::query()
                ->where('customer_id', $customer->getKey())
                ->where('is_default', true)
                ->exists()) {
                $fallback = Address::query()
                    ->where('customer_id', $customer->getKey())
                    ->orderBy('id')
                    ->first();

                $fallback?->update(['is_default' => true]);
            }
        });

        $model->refresh();

        $auditLogger->record(
            'customer.address_updated',
            $user,
            $model,
            $before,
            $this->addressPayload($model),
            $request,
        );

        return response()->json($this->addressPayload($model));
    }

    public function destroyAddress(
        Request $request,
        int $address,
        AuditLogger $auditLogger,
    ): Response {
        [$user, $customer] = $this->context($request);

        $model = Address::query()
            ->whereKey($address)
            ->where('customer_id', $customer->getKey())
            ->firstOrFail();

        $before = $this->addressPayload($model);
        $wasDefault = (bool) $model->is_default;

        DB::transaction(function () use ($model, $customer, $wasDefault): void {
            $model->delete();

            if ($wasDefault) {
                $fallback = Address::query()
                    ->where('customer_id', $customer->getKey())
                    ->orderBy('id')
                    ->first();

                $fallback?->update(['is_default' => true]);
            }
        });

        $auditLogger->record(
            'customer.address_deleted',
            $user,
            $model,
            $before,
            null,
            $request,
        );

        return response()->noContent();
    }

    public function favorites(Request $request): JsonResponse
    {
        [, $customer] = $this->context($request);

        return response()->json([
            'data' => $this->favoriteRows($customer),
        ]);
    }

    public function addFavorite(
        Request $request,
        int $product,
        AuditLogger $auditLogger,
    ): JsonResponse {
        [$user, $customer] = $this->context($request);

        $productModel = Product::query()
            ->whereKey($product)
            ->where('is_active', true)
            ->firstOrFail();

        $favorite = CustomerFavorite::query()->firstOrCreate([
            'customer_id' => $customer->getKey(),
            'product_id' => $productModel->getKey(),
        ]);

        $auditLogger->record(
            'customer.favorite_added',
            $user,
            $favorite,
            null,
            ['product_id' => (int) $productModel->getKey()],
            $request,
        );

        return response()->json([
            'product' => $this->favoriteProductPayload($productModel),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    public function removeFavorite(
        Request $request,
        int $product,
        AuditLogger $auditLogger,
    ): Response {
        [$user, $customer] = $this->context($request);

        $favorite = CustomerFavorite::query()
            ->where('customer_id', $customer->getKey())
            ->where('product_id', $product)
            ->firstOrFail();

        $auditLogger->record(
            'customer.favorite_removed',
            $user,
            $favorite,
            ['product_id' => (int) $favorite->product_id],
            null,
            $request,
        );

        $favorite->delete();

        return response()->noContent();
    }

    /** @return array{0: User, 1: Customer} */
    private function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $customer = Customer::query()
            ->where('user_id', $user->getKey())
            ->first();

        abort_unless($customer instanceof Customer, 403, 'Customer profile is required.');

        return [$user, $customer];
    }

    private function profilePayload(User $user, ?Customer $customer): array
    {
        $roles = $user->roles()
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();

        $storeIds = $user->storeRoleAssignments()
            ->select('store_id')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $addresses = $customer instanceof Customer
            ? Address::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (Address $address): array => $this->addressPayload($address))
                ->values()
                ->all()
            : [];

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'roles' => $roles,
            'store_ids' => $storeIds,
            'customer' => $customer instanceof Customer ? [
                'id' => (int) $customer->getKey(),
                'type' => (string) $customer->type,
                'name' => (string) $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ] : null,
            'addresses' => $addresses,
            'favorites' => $customer instanceof Customer
                ? $this->favoriteRows($customer)
                : [],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function addressRules(bool $partial): array
    {
        $prefix = $partial ? ['sometimes'] : ['required'];

        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'line1' => [...$prefix, 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => [...$prefix, 'string', 'max:120'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => [...$prefix, 'string', 'size:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizedAddressValues(array $validated): array
    {
        $values = [];

        foreach (['label', 'line1', 'line2', 'city', 'area', 'latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $validated)) {
                $values[$field] = $validated[$field];
            }
        }

        if (array_key_exists('country_code', $validated)) {
            $values['country_code'] = Str::upper((string) $validated['country_code']);
        }

        return $values;
    }

    private function addressPayload(Address $address): array
    {
        return [
            'id' => (int) $address->getKey(),
            'label' => $address->label,
            'line1' => (string) $address->line1,
            'line2' => $address->line2,
            'city' => (string) $address->city,
            'area' => $address->area,
            'country_code' => (string) $address->country_code,
            'latitude' => $address->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address->longitude === null ? null : (float) $address->longitude,
            'is_default' => (bool) $address->is_default,
        ];
    }

    private function favoriteRows(Customer $customer): array
    {
        return Product::query()
            ->select('products.*')
            ->join('customer_favorites', 'customer_favorites.product_id', '=', 'products.id')
            ->where('customer_favorites.customer_id', $customer->getKey())
            ->where('products.is_active', true)
            ->orderBy('customer_favorites.id')
            ->get()
            ->map(fn (Product $product): array => $this->favoriteProductPayload($product))
            ->values()
            ->all();
    }

    private function favoriteProductPayload(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'is_active' => (bool) $product->is_active,
        ];
    }
}
