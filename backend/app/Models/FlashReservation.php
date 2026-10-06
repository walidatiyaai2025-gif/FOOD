<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class FlashReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'selling_quantity' => 'decimal:3',
            'reserved_base_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'inventory_allocations' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('expires_at'));
    }

    public function confirmedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('confirmed_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    /** @return array<int, array{inventory_id:int, quantity:float}> */
    public function inventoryAllocations(): array
    {
        $value = $this->getAttribute('inventory_allocations');
        $items = is_array($value) ? $value : [];
        $allocations = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $inventoryId = isset($item['inventory_id']) && is_numeric($item['inventory_id'])
                ? (int) $item['inventory_id']
                : 0;
            $quantity = isset($item['quantity']) && is_numeric($item['quantity'])
                ? (float) $item['quantity']
                : 0.0;

            if ($inventoryId <= 0 || $quantity <= 0) {
                continue;
            }

            $allocations[] = [
                'inventory_id' => $inventoryId,
                'quantity' => $quantity,
            ];
        }

        return $allocations;
    }
}
