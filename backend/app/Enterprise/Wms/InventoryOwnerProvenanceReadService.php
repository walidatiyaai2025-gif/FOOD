<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A conservative read boundary for supplier/FOODEX legal stock title.
 *
 * Never use an aggregate inventory balance, legacy SKU association or standard
 * WMS unit cost as proof of legal ownership. An unapproved or stale partition
 * must not become a quote-time profitability/valuation authority.
 */
final class InventoryOwnerProvenanceReadService
{
    public function __construct(
        private readonly WarehouseScope $warehouses,
        private readonly InventoryLegalTitleAttestationVerifier $titleProof,
    ) {}

    /**
     * @return array{
     *   inventory_id:int,store_id:int,warehouse_id:int,product_id:int,
     *   quantity:string,reserved_quantity:string,state:string,
     *   certified:bool,positions:list<array{
     *     owner_kind:string,supplier_id:?int,supplier_name:?string,quantity:string,
     *     reserved_quantity:string,certified:bool
     *   }>
     * }
     */
    public function snapshot(User $actor, int $inventoryId): array
    {
        $inventory = DB::table('inventories')
            ->where('id', $inventoryId)
            ->first();

        abort_if($inventory === null, 404);

        $warehouse = $this->warehouses->assertWarehouse(
            $actor,
            (int) $inventory->warehouse_id,
            'inventory.view',
        );

        $rows = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventoryId)
            ->orderBy('owner_key')
            ->get();

        $quantity = self::millis($inventory->quantity);
        $reserved = self::millis($inventory->reserved_quantity);

        // New stock rows created by existing writers have no partition event.
        // Expose the entire balance as UNKNOWN rather than a misleading zero
        // or a certified FOODEX amount.
        if ($rows->isEmpty()) {
            // Lack of owner provenance must not camouflage an invalid
            // canonical stock balance. This is an accounting/inventory
            // integrity exception, not merely unknown legal ownership.
            $balanceConsistent = $quantity >= 0
                && $reserved >= 0
                && $reserved <= $quantity;

            return [
                'inventory_id' => $inventoryId,
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => (int) $inventory->warehouse_id,
                'product_id' => (int) $inventory->product_id,
                'quantity' => self::decimal($quantity),
                'reserved_quantity' => self::decimal($reserved),
                'state' => $balanceConsistent ? 'UNKNOWN' : 'STALE',
                'certified' => false,
                'positions' => [[
                    'owner_kind' => 'UNKNOWN',
                    'supplier_id' => null,
                    'supplier_name' => null,
                    'quantity' => self::decimal($quantity),
                    'reserved_quantity' => self::decimal($reserved),
                    'certified' => false,
                ]],
            ];
        }

        $total = 0;
        $totalReserved = 0;
        $allCertifiable = $rows->isNotEmpty();
        $unknown = $rows->isEmpty();
        $positions = [];

        // Tenant-scoped supplier identity is for display and validation only:
        // a supplier record/name is not proof of legal title. Load the names
        // in one bounded query, not one unscoped lookup per owner partition.
        $supplierIds = $rows->pluck('supplier_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $suppliers = collect();
        if ($supplierIds !== []) {
            $companyId = DB::table('enterprise_store_scopes as scope')
                ->join('enterprise_business_units as unit', 'unit.id', '=', 'scope.business_unit_id')
                ->join('enterprise_legal_entities as entity', 'entity.id', '=', 'unit.legal_entity_id')
                ->where('scope.store_id', (int) $warehouse->store_id)
                ->value('entity.company_id');

            if ($companyId !== null) {
                // Supplier identity must belong to the authorized legal
                // company, not merely share a manually assigned store_id.
                $suppliers = DB::table('enterprise_suppliers')
                    ->where('company_id', (int) $companyId)
                    ->where('status', 'active')
                    // Follow the canonical supplier directory: a legal
                    // company-wide supplier is visible to its company stores,
                    // but not to another company or another store's scope.
                    ->where(function ($scope) use ($warehouse): void {
                        $scope->where(function ($store) use ($warehouse): void {
                            $store->where('scope_type', 'store')
                                ->where('store_id', (int) $warehouse->store_id);
                        })->orWhere(function ($company): void {
                            $company->where('scope_type', 'company')
                                ->whereNull('store_id');
                        });
                    })
                    ->whereIn('id', $supplierIds)
                    ->get(['id', 'name_en', 'name_ar'])
                    ->keyBy('id');
            }
        }

        foreach ($rows as $row) {
            $kind = (string) $row->owner_kind;
            $supplierId = $row->supplier_id === null ? null : (int) $row->supplier_id;
            $validKey = match ($kind) {
                'UNKNOWN' => $row->owner_key === 'UNKNOWN' && $supplierId === null,
                'FOODEX' => $row->owner_key === 'FOODEX' && $supplierId === null,
                'SUPPLIER' => $supplierId !== null && $supplierId > 0
                    && $row->owner_key === 'SUPPLIER:'.$supplierId
                    && $suppliers->has($supplierId),
                default => false,
            };

            $proof = $validKey
                && $kind !== 'UNKNOWN'
                && $row->proposed_by !== null
                && $row->certified_by !== null
                && (int) $row->proposed_by !== (int) $row->certified_by
                && $row->certified_at !== null
                && trim((string) $row->evidence_type) !== ''
                && trim((string) $row->evidence_reference) !== ''
                // These two columns are untrusted descriptions, not a link to
                // an independently verified, immutable legal-title document.
                // Until the canonical provenance authority is implemented,
                // recorded maker/checker metadata must never certify title.
                && $this->titleProof->verifies($inventory, $row, (int) $warehouse->store_id);

            // The source balance must still be the balance that was certified.
            // Ordinary inventory writers do not yet update ownership partitions.
            $sameVersion = $row->source_inventory_updated_at !== null
                && $inventory->updated_at !== null
                && (string) $row->source_inventory_updated_at === (string) $inventory->updated_at;

            $lineQuantity = self::millis($row->quantity);
            $lineReserved = self::millis($row->reserved_quantity);
            $total += $lineQuantity;
            $totalReserved += $lineReserved;
            $allCertifiable = $allCertifiable
                && $proof && $sameVersion
                && $lineQuantity >= 0 && $lineReserved >= 0
                && $lineReserved <= $lineQuantity;
            $unknown = $unknown || $kind === 'UNKNOWN' || ! $validKey;

            $supplier = $validKey && $kind === 'SUPPLIER' ? $suppliers->get($supplierId) : null;
            $supplierName = $supplier === null ? null
                : (app()->getLocale() === 'ar' && trim((string) $supplier->name_ar) !== ''
                    ? (string) $supplier->name_ar : (string) $supplier->name_en);

            $positions[] = [
                'owner_kind' => $validKey ? $kind : 'UNKNOWN',
                'supplier_id' => $validKey ? $supplierId : null,
                'supplier_name' => $supplierName,
                'quantity' => self::decimal($lineQuantity),
                'reserved_quantity' => self::decimal($lineReserved),
                'certified' => $proof && $sameVersion,
            ];
        }

        $consistent = $total === $quantity
            && $totalReserved === $reserved
            && $quantity >= 0 && $reserved >= 0 && $reserved <= $quantity;

        $certified = $consistent && $allCertifiable && ! $unknown;
        $state = ! $consistent ? 'STALE'
            : ($unknown ? 'UNKNOWN'
                : ($certified ? 'CERTIFIED' : 'UNVERIFIED'));

        // Never expose a per-owner positive certification when the aggregate
        // inventory is stale, incomplete or includes unproven legal title.
        if (! $certified) {
            foreach ($positions as &$position) {
                $position['certified'] = false;
            }
            unset($position);
        }

        return [
            'inventory_id' => $inventoryId,
            'store_id' => (int) $warehouse->store_id,
            'warehouse_id' => (int) $inventory->warehouse_id,
            'product_id' => (int) $inventory->product_id,
            'quantity' => self::decimal($quantity),
            'reserved_quantity' => self::decimal($reserved),
            'state' => $state,
            'certified' => $certified,
            'positions' => $positions,
        ];
    }

    /**
     * Preserve all three fractional digits of the database DECIMAL(14,3).
     * Casting to float before comparing owner and canonical inventories can
     * round stock balances and must never establish legal-title consistency.
     */
    private static function millis(mixed $value): int
    {
        $raw = trim((string) $value);
        if (preg_match('/\A(-?)([0-9]{1,11})(?:\.([0-9]{1,3}))?\z/D', $raw, $parts) !== 1) {
            throw new \UnexpectedValueException('Invalid canonical inventory quantity.');
        }

        $whole = (int) $parts[2];
        $fraction = (int) str_pad($parts[3] ?? '', 3, '0');
        $millis = $whole * 1000 + $fraction;

        return $parts[1] === '-' ? -$millis : $millis;
    }

    private static function decimal(int $millis): string
    {
        $absolute = abs($millis);

        return ($millis < 0 ? '-' : '').intdiv($absolute, 1000).'.'
            .str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }
}
