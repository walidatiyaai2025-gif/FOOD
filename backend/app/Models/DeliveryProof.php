<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryProof extends Model
{
    protected $table = 'delivery_proofs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Delivery proof evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Delivery proof evidence is immutable.');
        });
    }
}
