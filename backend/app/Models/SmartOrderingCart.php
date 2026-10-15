<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOrderingCart extends Model
{
    protected $table = 'smart_ordering_carts';

    protected $guarded = [];

    /** @return array<string,mixed>|null */
    public function merchandisingContext(): ?array
    {
        $value = $this->getAttribute('merchandising_context');

        return is_array($value) ? $value : null;
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'merchandising_context' => 'array',
        ];
    }
}
