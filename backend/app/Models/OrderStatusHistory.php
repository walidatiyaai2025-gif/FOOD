<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    use ScopesStoreAccess;

    protected $table = 'order_status_history';

    protected $guarded = [];
}
