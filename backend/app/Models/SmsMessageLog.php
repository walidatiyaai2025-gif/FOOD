<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessageLog extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'operator_id'=>'integer',
            'attempts'=>'integer',
            'latency_ms'=>'integer',
            'is_test'=>'boolean',
            'sent_at'=>'datetime',
            'failed_at'=>'datetime',
        ];
    }
}
