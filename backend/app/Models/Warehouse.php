<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use ScopesStoreAccess;

    protected $table = 'warehouses';

    protected $guarded = [];
}
