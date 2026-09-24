<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use ScopesStoreAccess;

    protected $table = 'banners';

    protected $guarded = [];
}
