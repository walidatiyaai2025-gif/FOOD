<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use ScopesStoreAccess;

    protected $table = 'promotions';

    protected $guarded = [];
}
