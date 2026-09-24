<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    use ScopesStoreAccess;

    protected $table = 'store_products';

    protected $guarded = [];
}
