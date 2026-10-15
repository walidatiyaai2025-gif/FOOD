<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Models\SmartOrderingCart;
use App\Services\CommerceQuoteService;
use App\Services\SmartOrderingAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CartService
{
    public function __construct(
        private readonly BranchPolicyService $branches,
        private readonly CommerceQuoteService $quotes,
        private readonly SmartOrderingRevisionService $revisions,
        private readonly SmartOrderingMerchandisingService $merchandising,
        private readonly SmartOrderingModifierService $menuModifiers,
        private readonly SmartOrderingAnalyticsService $analytics,
        private readonly SmartOrderingAttributionService $attribution,
    ) {}

    /** @return array{cart:SmartOrderingCart,token:string,quote:array<string,mixed>} */
    public function create(
        string $branchPublicId,
        ?string $revisionPublicId = null,
        ?string $attributionContext = null,
    ): array {
        $branch = $this->branches->branch($branchPublicId);
        $this->branches->assertAccepting($branch);

        $site = $branch->site()->firstOrFail();
        $published = $this->revisions->published($site);
        abort_unless($published !== null, 409, 'Smart Ordering has no published revision.');
        if ($revisionPublicId !== null && ! hash_equals((string) $published->public_id, $revisionPublicId)) {
            abort(409, 'Requested Smart Ordering revision is stale or unpublished.');
        }

        $trustedAttribution = null;
        $trustedContext = null;
        if ($attributionContext !== null && trim($attributionContext) !== '') {
            $trustedAttribution = $this->attribution->serverContext(
                trim($attributionContext),
                (int) $branch->store_id,
            );

            $branchReference = trim((string) ($trustedAttribution['branch_reference'] ?? ''));
            if ($branchReference !== '') {
                if (! in_array($branchReference, [(string) $branch->public_id, (string) $branch->code], true)) {
                    abort(409, 'QR attribution branch does not match the selected Smart Ordering branch.');
                }
            }

            $trustedContext = [
                'qr_public_id' => (string) $trustedAttribution['qr_public_id'],
                'type' => (string) $trustedAttribution['type'],
                'campaign' => $trustedAttribution['campaign'] === null ? null : (string) $trustedAttribution['campaign'],
                'promotion_id' => $trustedAttribution['promotion_id'] === null ? null : (int) $trustedAttribution['promotion_id'],
            ];
        }
        $token = Str::random(64);
        $cart = SmartOrderingCart::query()->create([
            'public_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'branch_id' => $branch->getKey(),
            'revision_public_id' => (string) $published->public_id,
            'qr_public_id' => $trustedAttribution['qr_public_id'] ?? null,
            'source_snapshot' => $trustedAttribution['source'] ?? null,
            'campaign_snapshot' => $trustedAttribution['campaign'] ?? null,
            'offer_reference' => isset($trustedAttribution['promotion_id'])
                ? 'promotion:'.$trustedAttribution['promotion_id']
                : null,
            'status' => 'active',
            'merchandising_context' => $trustedContext,
            'expires_at' => now()->addHours(4),
        ]);

        $this->analytics->recordForCart(
            $cart,
            'cart_started',
            'cart:'.$cart->public_id.':started',
        );

        return ['cart' => $cart, 'token' => $token, 'quote' => $this->preview($cart)];
    }

    public function authorized(string $publicId, string $token, bool $allowCheckedOut = false): SmartOrderingCart
    {
        $cart = SmartOrderingCart::query()
            ->where('public_id', $publicId)
            ->whereIn('status', $allowCheckedOut ? ['active', 'checked_out'] : ['active'])
            ->firstOrFail();

        abort_unless(hash_equals((string) $cart->token_hash, hash('sha256', $token)), 404);
        $expiresAt = $cart->expires_at === null ? null : CarbonImmutable::parse((string) $cart->expires_at);
        abort_if($expiresAt?->isPast() === true, 410, 'Cart expired.');

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

        $requestedProductId = (int) ($payload['product_id'] ?? 0);
        $rawSelection = (array) ($payload['selection'] ?? []);
        $menuItemPublicId = isset($payload['menu_item_public_id'])
            ? trim((string) $payload['menu_item_public_id'])
            : null;
        if ($menuItemPublicId === '') {
            $menuItemPublicId = null;
        }
        $usePublicMenuSelection = $menuItemPublicId !== null
            && ($requestedProductId <= 0 || array_key_exists('menu_modifiers', $rawSelection));

        return DB::transaction(function () use (
            $cart,
            $payload,
            $requestedProductId,
            $quantity,
            $rawSelection,
            $menuItemPublicId,
            $usePublicMenuSelection,
        ): array {
            $branch = $this->branchForCart($cart);
            $this->branches->assertAccepting($branch);

            if ($usePublicMenuSelection) {
                [$productId, $selection] = $this->resolvePublicMenuSelection(
                    $cart,
                    $branch,
                    (string) $menuItemPublicId,
                    $rawSelection,
                );
                if ($requestedProductId > 0 && $requestedProductId !== $productId) {
                    throw ValidationException::withMessages([
                        'product_id' => ['Menu item identity does not match the selected product.'],
                    ]);
                }
            } else {
                $productId = $requestedProductId;
                abort_unless($productId > 0, 422, 'A valid product is required.');
                $selection = $this->selection($rawSelection);
                if ($menuItemPublicId !== null) {
                    $this->assertMenuItemForCart($cart, $branch, $menuItemPublicId, $productId);
                }
            }

            $this->assertProductBelongsToStore($productId, (int) $branch->store_id);

            $itemId = (int) DB::table('smart_ordering_cart_items')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'cart_id' => $cart->getKey(),
                'product_id' => $productId,
                'menu_item_public_id' => $menuItemPublicId,
                'quantity' => $quantity,
                'selection' => $selection === [] ? null : json_encode($selection, JSON_THROW_ON_ERROR),
                'note' => isset($payload['note']) ? mb_substr(trim((string) $payload['note']), 0, 500) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quote = $this->preview($cart, true);
            $item = DB::table('smart_ordering_cart_items')->where('id', $itemId)->first();

            $this->analytics->recordForCart(
                $cart,
                'item_added',
                'cart-item:'.$item->public_id.':added',
                [
                    'product_id' => $productId,
                    'metadata' => ['quantity' => $quantity],
                ],
            );

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
        $item = DB::table('smart_ordering_cart_items')
            ->where('cart_id', $cart->getKey())
            ->where('public_id', $itemPublicId)
            ->first(['public_id', 'product_id', 'quantity']);
        abort_unless($item !== null, 404);

        $deleted = DB::table('smart_ordering_cart_items')
            ->where('cart_id', $cart->getKey())
            ->where('public_id', $itemPublicId)
            ->delete();
        abort_unless($deleted === 1, 404);

        $this->analytics->recordForCart(
            $cart,
            'item_removed',
            'cart-item:'.$item->public_id.':removed',
            [
                'product_id' => (int) $item->product_id,
                'metadata' => ['quantity' => (float) $item->quantity],
            ],
        );

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
            'expires_at' => $cart->expires_at === null
                ? null
                : CarbonImmutable::parse((string) $cart->expires_at)->toAtomString(),
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

        $site = $branch->site()->firstOrFail();
        $quote = $this->quotes->quote(
            'b2c',
            (int) $branch->store_id,
            null,
            $lines,
            null,
            null,
            'cash_on_delivery',
            $strict,
            null,
            0.0,
            false,
        );

        return $this->merchandising->decorateQuote(
            $site,
            $branch,
            $lines,
            $quote,
            null,
            $cart->merchandisingContext(),
            0.0,
            false,
        )['quote'];
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

    /**
     * Resolve a public storefront item/modifier selection to the existing commerce product lines.
     *
     * @param  array<string,mixed>  $selection
     * @return array{0:int,1:array<string,mixed>}
     */
    private function resolvePublicMenuSelection(
        SmartOrderingCart $cart,
        SmartOrderingBranch $branch,
        string $menuItemPublicId,
        array $selection,
    ): array {
        foreach (['variant_product_id', 'modifiers', 'extras'] as $legacyKey) {
            if (array_key_exists($legacyKey, $selection)) {
                throw ValidationException::withMessages([
                    'selection' => ['Public menu selections cannot include internal product identifiers.'],
                ]);
            }
        }

        $revisionId = DB::table('smart_ordering_revisions')
            ->where('site_id', $branch->site_id)
            ->where('public_id', $cart->revision_public_id)
            ->where('status', 'published')
            ->value('id');
        abort_unless($revisionId !== null, 409, 'Cart menu revision is no longer available.');

        /** @var SmartOrderingMenuItem|null $item */
        $item = SmartOrderingMenuItem::query()
            ->where('revision_id', $revisionId)
            ->where('site_id', $branch->site_id)
            ->where('public_id', $menuItemPublicId)
            ->first();
        abort_unless($item instanceof SmartOrderingMenuItem, 404, 'Menu item is no longer available.');
        $this->assertMenuItemBranchScope($item, $branch);
        abort_unless($item->source_product_id !== null, 422, 'Menu item cannot be ordered.');

        $publicSelections = [];
        foreach ((array) ($selection['menu_modifiers'] ?? []) as $groupSelection) {
            abort_unless(is_array($groupSelection), 422, 'Invalid modifier selection.');
            $groupPublicId = trim((string) ($groupSelection['group_public_id'] ?? ''));
            abort_if($groupPublicId === '' || array_key_exists($groupPublicId, $publicSelections), 422, 'Invalid modifier selection.');
            $publicSelections[$groupPublicId] = array_values(array_map(
                'strval',
                (array) ($groupSelection['option_public_ids'] ?? []),
            ));
        }

        $site = $branch->site()->firstOrFail();
        $validated = $this->menuModifiers->quote($site, $item, $publicSelections, $branch);
        $internalModifiers = [];
        $publicModifiers = [];

        foreach ($validated['selections'] as $groupSelection) {
            $groupPublicId = (string) $groupSelection['group_id'];
            $optionIds = array_values(array_map('strval', (array) $groupSelection['option_ids']));
            $publicModifiers[] = [
                'group_public_id' => $groupPublicId,
                'option_public_ids' => $optionIds,
            ];

            if ($optionIds === []) {
                continue;
            }

            $options = DB::table('smart_ordering_modifier_options as options')
                ->join('smart_ordering_modifier_groups as groups', 'groups.id', '=', 'options.group_id')
                ->where('groups.item_id', $item->getKey())
                ->where('groups.public_id', $groupPublicId)
                ->whereIn('options.public_id', $optionIds)
                ->get(['options.public_id', 'options.source_product_id']);

            foreach ($optionIds as $optionPublicId) {
                $option = $options->first(
                    static fn (object $row): bool => (string) $row->public_id === $optionPublicId,
                );
                abort_unless($option !== null, 422, 'Modifier option is no longer available.');

                if ($option->source_product_id !== null) {
                    $modifierProductId = (int) $option->source_product_id;
                    $this->assertProductBelongsToStore($modifierProductId, (int) $branch->store_id);
                    $internalModifiers[] = [
                        'product_id' => $modifierProductId,
                        'quantity' => 1.0,
                    ];
                }
            }
        }

        $normalized = ['menu_modifiers' => $publicModifiers];
        if ($internalModifiers !== []) {
            $normalized['modifiers'] = $internalModifiers;
        }

        return [(int) $item->source_product_id, $normalized];
    }

    private function assertMenuItemBranchScope(SmartOrderingMenuItem $item, SmartOrderingBranch $branch): void
    {
        $menuId = DB::table('smart_ordering_menu_categories')
            ->where('id', $item->category_id)
            ->value('menu_id');
        abort_unless($menuId !== null, 404);

        $isScoped = DB::table('smart_ordering_menu_branches')
            ->where('menu_id', $menuId)
            ->exists();
        if ($isScoped) {
            abort_unless(
                DB::table('smart_ordering_menu_branches')
                    ->where('menu_id', $menuId)
                    ->where('branch_id', $branch->getKey())
                    ->exists(),
                404,
                'Menu item is not available for this branch.',
            );
        }
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

    private function assertMenuItemForCart(
        SmartOrderingCart $cart,
        SmartOrderingBranch $branch,
        string $publicId,
        int $productId,
    ): void {
        $revisionId = DB::table('smart_ordering_revisions')
            ->where('site_id', $branch->site_id)
            ->where('public_id', $cart->revision_public_id)
            ->where('status', 'published')
            ->value('id');

        abort_unless($revisionId !== null, 409, 'Cart menu revision is no longer available.');

        $item = DB::table('smart_ordering_menu_items')
            ->where('revision_id', $revisionId)
            ->where('site_id', $branch->site_id)
            ->where('public_id', $publicId)
            ->first(['source_product_id']);

        abort_unless(
            $item !== null
                && $item->source_product_id !== null
                && (int) $item->source_product_id === $productId,
            422,
            'Menu item identity does not match the selected product.',
        );
    }

    /** @return array<string,mixed> */
    private function itemPayload(?object $item): array
    {
        abort_unless($item !== null, 404);

        return [
            'public_id' => (string) $item->public_id,
            'product_id' => (int) $item->product_id,
            'menu_item_public_id' => $item->menu_item_public_id === null ? null : (string) $item->menu_item_public_id,
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
