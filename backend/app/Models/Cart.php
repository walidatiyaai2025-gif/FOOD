<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use ScopesStoreAccess;

    protected $table = 'carts';

    protected $guarded = [];
}
