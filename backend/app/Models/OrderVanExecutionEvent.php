<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderVanExecutionEvent extends Model
{
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
            throw new LogicException('Van delivery execution evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Van delivery execution evidence is immutable.');
        });
    }
}
