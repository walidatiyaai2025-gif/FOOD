<?php

namespace App\Domain\SmartOrdering;

use App\Models\SmartOrderingBranch;
use App\Models\SmartOrderingCart;
use App\Services\CommerceQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CartService
{
    public function __construct(
        private readonly BranchPolicyService $branches,
        private readonly CommerceQuoteService $quotes,
    ) {}

    /** @return array{cart:SmartOrderingCart,token:string,quote:array<string,mixed>} */
    public function create(string $branchPublicId, ?string $revisionPublicId = null): array
    {
        $branch = $this->branches->branch($branchPublicId);
        $this->branches->assertAccepting($branch);

        $token = Str::random(64);
        $cart = SmartOrderingCart::query()->create([
            'public_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'branch_id' => $branch->getKey(),
            'revision_public_id' => $revisionPublicId,
            'status' => 'active',
            'expires_at' => now()->addHours(4),
        ]);

        return ['cart' => $cart, 'token' => $token, 'quote' => $this->preview($cart)];
    }

    public function authorized(string $publicId, string $token, bool $allowCheckedOut = false): SmartOrderingCart
    {
        $cart = SmartOrderingCart::query()
            ->where('public_id', $publicId)
            ->whereIn('status', $allowCheckedOut ? ['active', 'checked_out'] : ['active'])
            ->firstOrFail();

        abort_unless(hash_equals((string) $cart->token_hash, hash('sha256', $token)), 404);
        abort_if($cart->expires_at !== null && $cart->expires_at->isPast(), 410, 'Cart expired.');

        return $cart;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function add(SmartOrderingCart $cart, array $payload): array
    {
        $quantity = round((float) ($payload['quantity'] ?? 0), 3);
        if ($quantity <= 0 || $quantity > 999) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }

        $productId = (int) ($payload['product_id'] ?? 0);
        abort_unless($productId > 0, 422, 'A valid product is required.');
        $selection = $this->selection((array) ($payload['selection'] ?? []));

        return DB::transaction(function () use ($cart, $payload, $productId, $quantity, $selection): array {
            $branch = $this->branchForCart($cart);
            $this->branches->assertAccepting($branch);
            $this->assertProductBelongsToStore($productId, (int) $branch->store_id);

            $itemId = (int) DB::table('smart_ordering_cart_items')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'cart_id' => $cart->getKey(),
                'product_id' => $productId,
                'quantity' => $quantity,
                'selection' => $selection === [] ? null : json_encode($selection, JSON_THROW_ON_ERROR),
                'note' => isset($payload['note']) ? mb_substr(trim((string) $payload['note']), 0, 500) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quote = $this->preview($cart, true);
            $item = DB::table('smart_ordering_cart_items')->where('id', $itemId)->first();

            return ['item' => $this->itemPayload($item), 'quote' => $quote];
        }, 3);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function update(SmartOrderingCart $cart, string $itemPublicId, array $payload): array
    {
        return DB::transaction(function () use ($cart, $itemPublicId, $payload): array {
            $item = DB::table('smart_ordering_cart_items')
                ->where('cart_id', $cart->getKey())
                ->where('public_id', $itemPublicId)
                ->lockForUpdate()
                ->first();
            abort_unless($item !== null, 404);

            $updates = ['updated_at' => now()];
            if (array_key_exists('quantity', $payload)) {
                $quantity = round((float) $payload['quantity'], 3);
                if ($quantity <= 0 || $quantity > 999) {
                    throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
                }
                $updates['quantity'] = $quantity;
            }
            if (array_key_exists('selection', $payload)) {
                $selection = $this->selection((array) $payload['selection']);
                $updates['selection'] = $selection === [] ? null : json_encode($selection, JSON_THROW_ON_ERROR);
            }
            if (array_key_exists('note', $payload)) {
                $updates['note'] = mb_substr(trim((string) $payload['note']), 0, 500);
            }

            DB::table('smart_ordering_cart_items')->where('id', $item->id)->update($updates);
            $quote = $this->preview($cart, true);
            $fresh = DB::table('smart_ordering_cart_items')->where('id', $item->id)->first();

            return ['item' => $this->itemPayload($fresh), 'quote' => $quote];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function remove(SmartOrderingCart $cart, string $itemPublicId): array
    {
        $deleted = DB::table('smart_ordering_cart_items')
            ->where('cart_id', $cart->getKey())
            ->where('public_id', $itemPublicId)
            ->delete();
        abort_unless($deleted === 1, 404);

        return $this->preview($cart);
    }

    /** @return array<string,mixed> */
    public function show(SmartOrderingCart $cart): array
    {
        $branch = $this->branchForCart($cart);
        $items = DB::table('smart_ordering_cart_items')
            ->where('cart_id', $cart->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (object $item): array => $this->itemPayload($item))
            ->all();

        return [
            'public_id' => (string) $cart->public_id,
            'branch' => [
                'public_id' => (string) $branch->public_id,
                'name' => (string) $branch->name,
            ],
            'revision_public_id' => $cart->revision_public_id,
            'expires_at' => $cart->expires_at?->toAtomString(),
            'items' => $items,
            'quote' => $this->preview($cart),
        ];
    }

    /** @return array<string,mixed> */
    public function preview(SmartOrderingCart $cart, bool $strict = false): array
    {
        $branch = $this->branchForCart($cart);
        $lines = $this->pricingLines($cart);

        if ($lines === []) {
            return [
                'pricing_source' => 'backend_authoritative_quote_v1',
                'currency' => 'EGP',
                'items' => [],
                'subtotal' => 0.0,
                'discount_total' => 0.0,
                'delivery_total' => 0.0,
                'tax_rate' => 0.0,
                'tax_total' => 0.0,
                'grand_total' => 0.0,
                'has_unavailable_items' => false,
            ];
        }

        return $this->quotes->quote(
            'b2c',
            (int) $branch->store_id,
            null,
            $lines,
            null,
            null,
            'cash_on_delivery',
            $strict,
        );
    }

    /** @return list<array{product_id:int,quantity:float}> */
    public function pricingLines(SmartOrderingCart $cart): array
    {
        $lines = [];

        foreach (DB::table('smart_ordering_cart_items')->where('cart_id', $cart->getKey())->orderBy('id')->get() as $item) {
            $selection = $this->decodeSelection($item->selection);
            $quantity = (float) $item->quantity;
            $primary = isset($selection['variant_product_id'])
                ? (int) $selection['variant_product_id']
                : (int) $item->product_id;
            $lines[] = ['product_id' => $primary, 'quantity' => $quantity];

            foreach (['modifiers', 'extras'] as $kind) {
                foreach ((array) ($selection[$kind] ?? []) as $option) {
                    $lines[] = [
                        'product_id' => (int) $option['product_id'],
                        'quantity' => round($quantity * (float) $option['quantity'], 3),
                    ];
                }
            }
        }

        return $lines;
    }

    public function branchForCart(SmartOrderingCart $cart): SmartOrderingBranch
    {
        return SmartOrderingBranch::query()->findOrFail((int) $cart->branch_id);
    }

    /** @return array<string,mixed> */
    public function cartSnapshot(SmartOrderingCart $cart): array
    {
        return [
            'cart_public_id' => (string) $cart->public_id,
            'revision_public_id' => $cart->revision_public_id,
            'items' => DB::table('smart_ordering_cart_items')
                ->where('cart_id', $cart->getKey())
                ->orderBy('id')
                ->get()
                ->map(fn (object $item): array => $this->itemPayload($item))
                ->all(),
        ];
    }

    /** @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    private function selection(array $selection): array
    {
        $normalized = [];
        if (isset($selection['variant_product_id'])) {
            $variant = (int) $selection['variant_product_id'];
            abort_unless($variant > 0, 422, 'Invalid variant selection.');
            $normalized['variant_product_id'] = $variant;
        }

        foreach (['modifiers', 'extras'] as $kind) {
            if (! isset($selection[$kind])) {
                continue;
            }
            abort_unless(is_array($selection[$kind]) && count($selection[$kind]) <= 20, 422, 'Invalid option selection.');
            $normalized[$kind] = [];
            foreach ($selection[$kind] as $option) {
                abort_unless(is_array($option), 422, 'Invalid option selection.');
                $productId = (int) ($option['product_id'] ?? 0);
                $quantity = round((float) ($option['quantity'] ?? 1), 3);
                abort_unless($productId > 0 && $quantity > 0 && $quantity <= 50, 422, 'Invalid option selection.');
                $normalized[$kind][] = ['product_id' => $productId, 'quantity' => $quantity];
            }
        }

        return $normalized;
    }

    private function assertProductBelongsToStore(int $productId, int $storeId): void
    {
        $exists = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->exists();

        abort_unless($exists, 404, 'Product is not available in this branch.');
    }

    /** @return array<string,mixed> */
    private function itemPayload(?object $item): array
    {
        abort_unless($item !== null, 404);

        return [
            'public_id' => (string) $item->public_id,
            'product_id' => (int) $item->product_id,
            'quantity' => (float) $item->quantity,
            'selection' => $this->decodeSelection($item->selection),
            'note' => $item->note,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeSelection(mixed $selection): array
    {
        if (is_array($selection)) {
            return $selection;
        }
        if (! is_string($selection) || $selection === '') {
            return [];
        }

        $decoded = json_decode($selection, true);

        return is_array($decoded) ? $decoded : [];
    }
}
