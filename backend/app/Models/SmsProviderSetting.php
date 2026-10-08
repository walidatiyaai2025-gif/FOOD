<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsProviderSetting extends Model
{
    protected $guarded=[];
    protected $hidden=['api_token_encrypted'];

    protected function casts(): array
    {
        return [
            'enabled'=>'boolean',
            'delivery_logging'=>'boolean',
            'otp_sender_enabled'=>'boolean',
            'notification_sender_enabled'=>'boolean',
            'api_token_encrypted'=>'encrypted',
            'request_timeout'=>'integer',
            'retry_count'=>'integer',
            'retry_backoff_seconds'=>'integer',
        ];
    }

    public function tokenConfigured(): bool
    {
        return trim((string) $this->getAttribute('api_token_encrypted')) !== '';
    }
}
