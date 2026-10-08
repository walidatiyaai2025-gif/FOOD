<?php

namespace App\Services\Sms;

use App\Models\SmsProviderSetting;

interface SmsProviderInterface
{
    /**
     * @return array{status:string,provider_code:string,error_code:?string,message:string,latency_ms:int,transient:bool}
     */
    public function send(SmsProviderSetting $setting, SmsMessage $message): array;
}
