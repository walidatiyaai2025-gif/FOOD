<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use ScopesStoreAccess;

    protected $table = 'settings';

    protected $guarded = [];
}
