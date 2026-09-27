<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use ScopesStoreAccess;

    protected $table = 'drivers';

    protected $guarded = [];
}
