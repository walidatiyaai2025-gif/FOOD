<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use ScopesStoreAccess;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'quoted_at' => 'datetime',
        'pricing_snapshot' => 'array',
    ];
}
