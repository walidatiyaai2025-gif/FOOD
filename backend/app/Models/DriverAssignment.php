<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;

class DriverAssignment extends Model
{
    use ScopesStoreAccess;

    protected $table = 'driver_assignments';

    protected $guarded = [];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
