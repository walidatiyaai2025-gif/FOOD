<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\CustomerFavorite;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerAddressService;
use App\Services\CustomerDomainResolver;
use App\Services\RetailMerchantIdentityService;
use App\Services\WholesalePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $hasBusinessProfile = B2bCustomer::query()->where('user_id', $user->getKey())->exists()
            || B2cCustomer::query()->where('user_id', $user->getKey())->exists()
            || DB::table('customers')->where('user_id', $user->getKey())->exists();

        if (! $hasBusinessProfile) {
            return response()->json($this->identityPayload($user));
        }

        [$customer, $channel] = app(CustomerDomainResolver::class)->profile($user, $request);

        return response()->json($this->profilePayload($user, $customer, $channel));
    }

    public function update(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $customer, $channel] = $this->context($request);

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

        return response()->json($this->profilePayload($user, $customer, $channel));
    }

    public function addresses(Request $request): JsonResponse
    {
        [$user, $customer, $channel] = $this->addressContext($request);
        $addresses = app(CustomerAddressService::class)
            ->queryFor($user, $customer, $channel)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (Address $address): array => $this->addressPayload($address))
            ->values()
            ->all();

        return response()->json(['data' => $addresses]);
    }

    public function showAddress(Request $request, int $address): JsonResponse
    {
        [$user, $customer, $channel] = $this->addressContext($request);

        $model = app(CustomerAddressService::class)
            ->findOwned($user, $address, $customer, $channel);

        return response()->json($this->addressPayload($model));
    }

    public function storeAddress(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $customer, $channel] = $this->addressContext($request);
        $validated = $request->validate($this->addressRules(false));
        $this->assertLocationSemantics($validated);
        $service = app(CustomerAddressService::class);

        $address = DB::transaction(function () use (
            $service,
            $user,
            $customer,
            $channel,
            $validated,
        ): Address {
            // Serialize default-address decisions for this owner so two concurrent
            // writes cannot both become the active default.
            $service->queryFor($user, $customer, $channel)
                ->lockForUpdate()
                ->get();

            $shouldDefault = (bool) ($validated['is_default'] ?? false)
                || ! $service->queryFor($user, $customer, $channel)->exists();

            if ($shouldDefault) {
                $service->queryFor($user, $customer, $channel)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            return Address::query()->create([
                ...$service->ownerAttributes($user, $customer, $channel),
                ...$this->normalizedAddressValues($validated),
                'is_default' => $shouldDefault,
            ]);
        }, 3);

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
        [$user, $customer, $channel] = $this->addressContext($request);
        $service = app(CustomerAddressService::class);
        $validated = $request->validate($this->addressRules(true));

        $existing = $service->findOwned($user, $address, $customer, $channel);
        $this->assertLocationSemantics($validated, $existing);
        $before = $this->addressPayload($existing);

        $model = DB::transaction(function () use (
            $service,
            $user,
            $customer,
            $channel,
            $address,
            $validated,
        ): Address {
            $service->queryFor($user, $customer, $channel)
                ->lockForUpdate()
                ->get();

            $locked = $service->queryFor($user, $customer, $channel)
                ->whereKey($address)
                ->firstOrFail();

            if (($validated['is_default'] ?? false) === true) {
                $service->queryFor($user, $customer, $channel)
                    ->whereKeyNot($locked->getKey())
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $values = $this->normalizedAddressValues($validated);
            if ($values !== []) {
                $locked->fill($values);
            }

            if (array_key_exists('is_default', $validated)) {
                $locked->is_default = (bool) $validated['is_default'];
            }

            $locked->save();

            if (! $service->queryFor($user, $customer, $channel)
                ->where('is_default', true)
                ->exists()) {
                $fallback = $service->queryFor($user, $customer, $channel)
                    ->orderBy('id')
                    ->first();

                $fallback?->update(['is_default' => true]);
            }

            return $locked->refresh();
        }, 3);

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

    public function setDefaultAddress(
        Request $request,
        int $address,
        AuditLogger $auditLogger,
    ): JsonResponse {
        [$user, $customer, $channel] = $this->addressContext($request);
        $service = app(CustomerAddressService::class);

        $existing = $service->findOwned($user, $address, $customer, $channel);
        $before = $this->addressPayload($existing);

        $model = DB::transaction(function () use (
            $service,
            $user,
            $customer,
            $channel,
            $address,
        ): Address {
            $service->queryFor($user, $customer, $channel)
                ->lockForUpdate()
                ->get();

            $locked = $service->queryFor($user, $customer, $channel)
                ->whereKey($address)
                ->firstOrFail();

            $service->queryFor($user, $customer, $channel)
                ->whereKeyNot($locked->getKey())
                ->update(['is_default' => false, 'updated_at' => now()]);

            $locked->is_default = true;
            $locked->save();

            return $locked->refresh();
        }, 3);

        $auditLogger->record(
            'customer.address_default_changed',
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
        [$user, $customer, $channel] = $this->addressContext($request);
        $service = app(CustomerAddressService::class);

        $model = $service->findOwned($user, $address, $customer, $channel);
        $before = $this->addressPayload($model);
        $wasDefault = (bool) $model->is_default;

        DB::transaction(function () use (
            $service,
            $user,
            $customer,
            $channel,
            $model,
            $wasDefault,
        ): void {
            $service->queryFor($user, $customer, $channel)
                ->lockForUpdate()
                ->get();

            $model->delete();

            if ($wasDefault) {
                $fallback = $service->queryFor($user, $customer, $channel)
                    ->orderBy('id')
                    ->first();

                $fallback?->update(['is_default' => true]);
            }
        }, 3);

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
        [, $customer, $channel] = $this->context($request);
        $storeId = $this->favoriteStoreId($customer, $channel);

        return response()->json([
            'data' => $this->favoriteRows($customer, $storeId, $channel),
        ]);
    }

    public function addFavorite(
        Request $request,
        int $product,
        AuditLogger $auditLogger,
    ): JsonResponse {
        [$user, $customer, $channel] = $this->context($request);
        $storeId = $this->favoriteStoreId($customer, $channel);
        $legacyCustomerId = app(CustomerDomainResolver::class)->legacyId($customer);

        $productModel = Product::query()
            ->whereKey($product)
            ->where('is_active', true)
            ->whereExists(function ($query) use ($storeId): void {
                $query->selectRaw('1')
                    ->from('store_products')
                    ->whereColumn('store_products.product_id', 'products.id')
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true);
            })
            ->firstOrFail();

        $favorite = CustomerFavorite::query()->firstOrCreate([
            'customer_id' => $legacyCustomerId,
            'product_id' => $productModel->getKey(),
        ], [
            'b2c_customer_id' => $customer instanceof B2cCustomer
                ? $customer->getKey()
                : null,
        ]);

        if ($customer instanceof B2cCustomer && $favorite->b2c_customer_id === null) {
            $favorite->forceFill(['b2c_customer_id' => $customer->getKey()])->save();
        }

        $auditLogger->record(
            'customer.favorite_added',
            $user,
            $favorite,
            null,
            [
                'product_id' => (int) $productModel->getKey(),
                'channel' => $channel,
                'store_id' => $storeId,
            ],
            $request,
        );

        return response()->json([
            'product' => $this->favoriteProductPayload(
                $productModel,
                $storeId,
                $channel,
            ),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    public function removeFavorite(
        Request $request,
        int $product,
        AuditLogger $auditLogger,
    ): Response {
        [$user, $customer, $channel] = $this->context($request);
        $storeId = $this->favoriteStoreId($customer, $channel);
        $legacyCustomerId = app(CustomerDomainResolver::class)->legacyId($customer);

        $favorite = CustomerFavorite::query()
            ->where('customer_id', $legacyCustomerId)
            ->where('product_id', $product)
            ->firstOrFail();

        $auditLogger->record(
            'customer.favorite_removed',
            $user,
            $favorite,
            [
                'product_id' => (int) $favorite->product_id,
                'channel' => $channel,
                'store_id' => $storeId,
            ],
            null,
            $request,
        );

        $favorite->delete();

        return response()->noContent();
    }

    /** @return array{0: User, 1: B2bCustomer|B2cCustomer, 2: string} */
    private function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $requestedDomain = strtolower(trim((string) $request->header(
            'X-FOODEX-Customer-Domain',
            '',
        )));
        if ($requestedDomain === 'b2b') {
            return [
                $user,
                app(CustomerDomainResolver::class)->b2bFromRequest($user, $request),
                'b2b',
            ];
        }

        [$customer, $channel] = app(CustomerDomainResolver::class)->profile($user, $request);

        return [$user, $customer, $channel];
    }

    /** @return array{0: User, 1: B2bCustomer|B2cCustomer|null, 2: string|null} */
    private function addressContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (app(CustomerAddressService::class)->platformCustomer($user) !== null) {
            $channel = strtolower(trim((string) $request->header(
                'X-FOODEX-Customer-Domain',
                'b2c',
            )));
            abort_unless(
                in_array($channel, ['b2b', 'b2c'], true),
                400,
                'Unsupported customer address commerce channel.',
            );

            $customer = $channel === 'b2b'
                ? app(CustomerDomainResolver::class)->b2bFromRequest($user, $request)
                : null;

            return [$user, $customer, $channel];
        }

        [$customer, $channel] = app(CustomerDomainResolver::class)->profile($user, $request);

        return [$user, $customer, $channel];
    }

    private function identityPayload(User $user): array
    {
        $roles = $user->roles()
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();

        $storeIds = $user->storeRoleAssignments()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'roles' => $roles,
            'store_ids' => $storeIds,
            ...app(RetailMerchantIdentityService::class)->identityPayload($user),
            'customer' => null,
            'addresses' => [],
            'favorites' => [],
        ];
    }

    private function profilePayload(User $user, B2bCustomer|B2cCustomer $customer, string $channel): array
    {
        /** @var B2bAccount|null $businessAccount */
        $businessAccount = $customer instanceof B2bCustomer
            ? $customer->account()->first()
            : null;
        $roles = $user->roles()
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();

        $storeIds = $customer instanceof B2cCustomer
            ? [(int) $customer->store_id]
            : [];

        $addresses = app(CustomerAddressService::class)
            ->queryFor($user, $customer, $channel)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (Address $address): array => $this->addressPayload($address))
            ->values()
            ->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'roles' => $roles,
            'store_ids' => $storeIds,
            ...app(RetailMerchantIdentityService::class)->identityPayload($user),
            'customer' => [
                'id' => (int) $customer->getKey(),
                'type' => $channel,
                'store_id' => $customer instanceof B2cCustomer ? (int) $customer->store_id : null,
                'name' => (string) $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
            'business_account' => $businessAccount === null ? null : [
                'id' => (int) $businessAccount->getKey(),
                'company_name' => (string) $businessAccount->company_name,
                'status' => (string) $businessAccount->status,
                'tax_number' => $businessAccount->tax_number,
            ],
            'addresses' => $addresses,
            'favorites' => $customer instanceof B2cCustomer
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
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'line1' => [...$prefix, 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => [...$prefix, 'string', 'max:120'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => [...$prefix, 'string', 'size:2'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'governorate' => ['sometimes', 'nullable', 'string', 'max:120'],
            'block' => ['sometimes', 'nullable', 'string', 'max:120'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avenue' => ['sometimes', 'nullable', 'string', 'max:120'],
            'building' => ['sometimes', 'nullable', 'string', 'max:120'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'apartment' => ['sometimes', 'nullable', 'string', 'max:120'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_accuracy_meters' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'location_source' => ['sometimes', Rule::in(['manual', 'current_location', 'map_pin'])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Current-location and map-pin addresses must never claim a geographic
     * source without a complete coordinate pair. On PATCH, validate the final
     * state after applying the submitted values to the existing address.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertLocationSemantics(array $validated, ?Address $existing = null): void
    {
        $source = array_key_exists('location_source', $validated)
            ? (string) $validated['location_source']
            : (string) ($existing?->location_source ?: 'manual');

        $latitude = array_key_exists('latitude', $validated)
            ? $validated['latitude']
            : $existing?->latitude;
        $longitude = array_key_exists('longitude', $validated)
            ? $validated['longitude']
            : $existing?->longitude;

        if (in_array($source, ['current_location', 'map_pin'], true)
            && ($latitude === null || $longitude === null)) {
            throw ValidationException::withMessages([
                'latitude' => ['Latitude and longitude are required for the selected location source.'],
                'longitude' => ['Latitude and longitude are required for the selected location source.'],
            ]);
        }

        if (array_key_exists('location_accuracy_meters', $validated)
            && $validated['location_accuracy_meters'] !== null
            && ($latitude === null || $longitude === null)) {
            throw ValidationException::withMessages([
                'location_accuracy_meters' => ['Location accuracy requires a saved coordinate pair.'],
            ]);
        }
    }

    private function normalizedAddressValues(array $validated): array
    {
        $values = [];

        foreach ([
            'label',
            'recipient_name',
            'delivery_phone',
            'line1',
            'line2',
            'city',
            'area',
            'country',
            'governorate',
            'block',
            'street',
            'avenue',
            'building',
            'floor',
            'apartment',
            'landmark',
            'delivery_notes',
            'latitude',
            'longitude',
            'location_accuracy_meters',
            'location_source',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $values[$field] = $validated[$field];
            }
        }

        if (array_key_exists('country_code', $validated)) {
            $values['country_code'] = Str::upper((string) $validated['country_code']);
        }

        if (
            array_key_exists('line1', $values)
            && ! array_key_exists('street', $values)
            && $values['line1'] !== null
        ) {
            $values['street'] = $values['line1'];
        }

        return $values;
    }

    private function addressPayload(Address $address): array
    {
        return [
            'id' => (int) $address->getKey(),
            'commerce_channel' => (string) $address->commerce_channel,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'delivery_phone' => $address->delivery_phone,
            'line1' => (string) $address->line1,
            'line2' => $address->line2,
            'city' => (string) $address->city,
            'area' => $address->area,
            'country_code' => (string) $address->country_code,
            'country' => $address->country,
            'governorate' => $address->governorate,
            'block' => $address->block,
            'street' => $address->street,
            'avenue' => $address->avenue,
            'building' => $address->building,
            'floor' => $address->floor,
            'apartment' => $address->apartment,
            'landmark' => $address->landmark,
            'delivery_notes' => $address->delivery_notes,
            'latitude' => $address->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address->longitude === null ? null : (float) $address->longitude,
            'location_accuracy_meters' => $address->location_accuracy_meters === null
                ? null
                : (float) $address->location_accuracy_meters,
            'location_source' => (string) ($address->location_source ?: 'manual'),
            'is_default' => (bool) $address->is_default,
            'created_at' => $address->created_at?->toAtomString(),
            'updated_at' => $address->updated_at?->toAtomString(),
        ];
    }

    private function favoriteRows(
        B2bCustomer|B2cCustomer $customer,
        ?int $storeId = null,
        ?string $channel = null,
    ): array {
        $channel ??= $customer instanceof B2bCustomer ? 'b2b' : 'b2c';
        $storeId ??= $this->favoriteStoreId($customer, $channel);

        $query = Product::query()
            ->select('products.*')
            ->join('customer_favorites', 'customer_favorites.product_id', '=', 'products.id');

        if ($customer instanceof B2cCustomer) {
            $legacyCustomerId = $customer->legacy_customer_id;
            $query->where(function ($favorites) use ($customer, $legacyCustomerId): void {
                $favorites->where(
                    'customer_favorites.b2c_customer_id',
                    $customer->getKey(),
                );
                if ($legacyCustomerId !== null) {
                    $favorites->orWhere(
                        'customer_favorites.customer_id',
                        (int) $legacyCustomerId,
                    );
                }
            });
        } else {
            $query->where(
                'customer_favorites.customer_id',
                app(CustomerDomainResolver::class)->legacyId($customer),
            );
        }

        return $query
            ->whereExists(function ($storeProducts) use ($storeId): void {
                $storeProducts->selectRaw('1')
                    ->from('store_products')
                    ->whereColumn('store_products.product_id', 'products.id')
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true);
            })
            ->where('products.is_active', true)
            ->orderBy('customer_favorites.id')
            ->get()
            ->map(fn (Product $product): array => $this->favoriteProductPayload(
                $product,
                $storeId,
                $channel,
            ))
            ->values()
            ->all();
    }

    private function favoriteStoreId(
        B2bCustomer|B2cCustomer $customer,
        string $channel,
    ): int {
        if ($channel === 'b2c' && $customer instanceof B2cCustomer) {
            return (int) $customer->store_id;
        }

        abort_unless(
            $channel === 'b2b' && $customer instanceof B2bCustomer,
            403,
        );

        return app(WholesalePrincipal::class)->storeId();
    }

    private function favoriteProductPayload(
        Product $product,
        int $storeId,
        string $channel,
    ): array {
        $imagePath = DB::table('product_images')
            ->where('product_id', $product->getKey())
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('path');
        $storeProduct = DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $product->getKey())
            ->first(['price']);

        return [
            'id' => (int) $product->getKey(),
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'store_id' => $storeId,
            'channel' => $channel,
            'price' => $storeProduct?->price === null ? null : (float) $storeProduct->price,
            'currency' => 'EGP',
            'image_url' => $imagePath === null
                ? null
                : url('/'.ltrim((string) $imagePath, '/')),
            'is_active' => (bool) $product->is_active,
        ];
    }
}
