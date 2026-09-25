<?php
namespace App\Services;
use App\Models\Notification;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PushDeliveryService
{
    public function validateProvider(PushProviderSetting $provider): void
    {
        $credentials=$provider->credentials_encrypted;
        if (!is_array($credentials)) throw ValidationException::withMessages(['credentials_json'=>'Provider credentials are required.']);
        foreach ($provider->platform==='android'?['project_id','access_token']:['bundle_id','bearer_token'] as $key) {
            if (!isset($credentials[$key])||!is_string($credentials[$key])||trim($credentials[$key])==='') {
                throw ValidationException::withMessages(['credentials_json'=>"Missing provider credential: {$key}."]);
            }
        }
    }

    public function send(PushProviderSetting $provider, PushDeviceToken $device, array $payload, bool $isTest=false): PushDeliveryLog
    {
        $log=PushDeliveryLog::query()->create(['user_id'=>$device->user_id,'device_id'=>$device->id,'app'=>$device->app,'platform'=>$device->platform,'environment'=>$device->environment,'status'=>'sending','is_test'=>$isTest]);
        try {
            $this->validateProvider($provider); $credentials=$provider->credentials_encrypted;
            $response=$provider->platform==='android'?$this->firebase($provider,$device,$payload,$credentials):$this->apns($provider,$device,$payload,$credentials);
            $body=$response->json(); $ok=$response->successful();
            $log->update(['status'=>$ok?'sent':'failed','response_code'=>$response->status(),'provider_message_id'=>is_array($body)?($body['name']??($body['id']??null)):null,'error_code'=>$ok?null:'provider_http_'.$response->status(),'error_message'=>$ok?null:$this->safeError($body)]);
        } catch (Throwable $e) {
            $log->update(['status'=>'failed','error_code'=>class_basename($e),'error_message'=>mb_substr($e->getMessage(),0,500)]);
        }
        return $log->fresh();
    }

    public function dispatchNotification(Notification $notification): void
    {
        if (!in_array($notification->channel,['push','both'],true)) return;
        $devices=PushDeviceToken::query()->whereNull('revoked_at')
            ->when($notification->app!=='all',fn($q)=>$q->where('app',$notification->app))
            ->when($notification->audience==='customer',fn($q)=>$q->where('app','customer'))
            ->when($notification->audience==='driver',fn($q)=>$q->where('app','driver'))
            ->when($notification->audience==='user',fn($q)=>$q->where('user_id',$notification->user_id))
            ->with('user:id,locale')->limit(500)->get();
        foreach ($devices as $device) {
            $provider=PushProviderSetting::query()->where('app',$device->app)->where('platform',$device->platform)->where('environment',$device->environment)->where('enabled',true)->first();
            if ($provider===null) {
                PushDeliveryLog::query()->create(['user_id'=>$device->user_id,'device_id'=>$device->id,'app'=>$device->app,'platform'=>$device->platform,'environment'=>$device->environment,'status'=>'skipped','error_code'=>'provider_not_configured','error_message'=>'No enabled provider configuration exists for this device.']);
                continue;
            }
            $en=$device->user?->locale==='en';
            $this->send($provider,$device,['title'=>$en?$notification->title_en:$notification->title_ar,'body'=>$en?$notification->body_en:$notification->body_ar,'data'=>['notification_id'=>(string)$notification->id,'type'=>(string)$notification->type]]);
        }
    }

    private function firebase(PushProviderSetting $p, PushDeviceToken $d, array $payload, array $c): Response
    {
        return Http::acceptJson()->withToken((string)$c['access_token'])->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string)$c['project_id']).'/messages:send',[
                'message'=>['token'=>$d->plainToken(),'notification'=>['title'=>$payload['title']??'','body'=>$payload['body']??''],'data'=>$payload['data']??[],
                    'android'=>['notification'=>array_filter(['sound'=>$p->default_sound,'channel_id'=>$p->default_channel,'icon'=>$p->default_icon])]]
            ]);
    }

    private function apns(PushProviderSetting $p, PushDeviceToken $d, array $payload, array $c): Response
    {
        $host=$p->environment==='production'?'https://api.push.apple.com':'https://api.sandbox.push.apple.com';
        return Http::acceptJson()->withToken((string)$c['bearer_token'])->withHeaders(['apns-topic'=>(string)$c['bundle_id']])->timeout(10)
            ->post($host.'/3/device/'.rawurlencode($d->plainToken()),['aps'=>['alert'=>['title'=>$payload['title']??'','body'=>$payload['body']??''],'sound'=>$p->default_sound??'default','category'=>$p->default_category],'data'=>$payload['data']??[]]);
    }

    private function safeError(mixed $body): string
    {
        $message=is_array($body)?(data_get($body,'error.message')??data_get($body,'reason')??'Provider rejected the request.'):'Provider rejected the request.';
        return mb_substr((string)$message,0,500);
    }
}
