<?php

namespace App\Domain\Assistant\Support;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssistantPageContext
{
    private const ENTITY_KEYS = ['order_id', 'customer_id', 'driver_id', 'product_id'];

    public function __construct(private readonly OperationalTenantScope $scope) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{path:string,channel:?string,store_id:?int,authorized_entities:array<string,mixed>}
     */
    public function resolve(User $user, array $input): array
    {
        $path = $this->path($input['path'] ?? null);
        $pathChannel = str_starts_with($path, '/admin/b2b') ? 'b2b'
            : (str_starts_with($path, '/admin/b2c') ? 'b2c' : null);
        $channel = $this->channel($input['channel'] ?? $input['store_channel'] ?? null);

        if ($channel !== null && $pathChannel !== null && $channel !== $pathChannel) {
            throw ValidationException::withMessages([
                'context.channel' => ['Assistant page context channel does not match the current management page.'],
            ]);
        }

        $channel ??= $pathChannel;
        $storeId = $this->positiveInt($input['store_id'] ?? $input['storeId'] ?? null);

        if ($storeId !== null) {
            $channel = $this->scope->assertStore($user, $storeId, 'assistant.use', $channel);
        }

        $allowedStoreIds = $storeId === null
            ? $this->scope->allowedStoreIds($user, 'assistant.use', $channel)
            : [$storeId];

        $authorized = [];
        if ($storeId !== null) {
            $authorized['store_id'] = $storeId;
        }
        if ($channel !== null) {
            $authorized['channel'] = $channel;
        }

        foreach (self::ENTITY_KEYS as $key) {
            $id = $this->positiveInt($input[$key] ?? null);
            if ($id === null) {
                continue;
            }

            abort_unless($this->entityVisible($key, $id, $allowedStoreIds, $channel), 404);
            $authorized[$key] = $id;
        }

        return [
            'path' => $path,
            'channel' => $channel,
            'store_id' => $storeId,
            'authorized_entities' => $authorized,
        ];
    }

    /** @param list<int> $storeIds */
    private function entityVisible(string $key, int $id, array $storeIds, ?string $channel): bool
    {
        if ($storeIds === []) {
            return false;
        }

        return match ($key) {
            'order_id' => DB::table('orders')
                ->where('id', $id)
                ->whereIn('store_id', $storeIds)
                ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
                ->exists(),
            'driver_id' => DB::table('drivers')
                ->where('id', $id)
                ->whereIn('store_id', $storeIds)
                ->when($channel !== null, fn ($query) => $query->where('driver_type', $channel))
                ->exists(),
            'product_id' => DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->where('products.id', $id)
                ->whereIn('catalogs.store_id', $storeIds)
                ->when($channel !== null, fn ($query) => $query->where('catalogs.channel', $channel))
                ->exists(),
            'customer_id' => $this->customerVisible($id, $storeIds, $channel),
            default => false,
        };
    }

    /** @param list<int> $storeIds */
    private function customerVisible(int $id, array $storeIds, ?string $channel): bool
    {
        if ($channel === 'b2c') {
            return DB::table('b2c_customers')
                ->where('id', $id)
                ->whereIn('store_id', $storeIds)
                ->exists();
        }

        if ($channel === 'b2b') {
            return DB::table('orders')
                ->where('channel', 'b2b')
                ->where('b2b_customer_id', $id)
                ->whereIn('store_id', $storeIds)
                ->exists();
        }

        return DB::table('b2c_customers')
            ->where('id', $id)
            ->whereIn('store_id', $storeIds)
            ->exists()
            || DB::table('orders')
                ->where('channel', 'b2b')
                ->where('b2b_customer_id', $id)
                ->whereIn('store_id', $storeIds)
                ->exists();
    }

    private function path(mixed $value): string
    {
        if (! is_string($value)) {
            return '/admin';
        }

        $path = trim($value);
        if ($path === '' || strlen($path) > 512 || ! str_starts_with($path, '/admin')) {
            return '/admin';
        }

        return $path;
    }

    private function channel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array(strtolower($value), ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages([
                'context.channel' => ['Assistant page context channel must be b2b or b2c.'],
            ]);
        }

        return strtolower($value);
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
