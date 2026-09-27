<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use ScopesStoreAccess;

    protected $table = 'stock_movements';

    protected $guarded = [];
}
