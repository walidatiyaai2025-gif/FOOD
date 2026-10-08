<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsMessageLog;
use App\Models\SmsProviderSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmsSettingsController extends Controller
{
    public function index(Request $request,SmsGateway $gateway): View
    {
        $this->authorizeAny($request);

        $setting=SmsProviderSetting::query()
            ->where('provider','advansys_bulk_sms')
            ->where('environment','production')
            ->first();

        return view('admin.sms-settings',[
            'setting'=>$setting,
            'maskedToken'=>$gateway->maskToken($setting?->getAttribute('api_token_encrypted')),
            'recentLogs'=>SmsMessageLog::query()->latest()->limit(50)->get(),
        ]);
    }

    public function update(Request $request,AuditLogger $audit): RedirectResponse
    {
        $actor=$this->authorize($request,'sms_settings.manage');
        $data=$request->validate([
            'enabled'=>['nullable','boolean'],
            'provider'=>['required','in:advansys_bulk_sms'],
            'api_base_url'=>['required','url','max:255'],
            'endpoint_path'=>['required','string','max:255'],
            'api_token'=>['nullable','string','max:4096'],
            'default_sender_name'=>['required','string','max:64'],
            'default_country_code'=>['required','regex:/^[0-9]{1,4}$/'],
            'operator_resolution_mode'=>['required','in:automatic,manual'],
            'request_timeout'=>['required','integer','min:1','max:30'],
            'retry_count'=>['required','integer','min:0','max:4'],
            'retry_backoff_seconds'=>['required','integer','min:0','max:10'],
            'delivery_logging'=>['nullable','boolean'],
            'otp_sender_enabled'=>['nullable','boolean'],
            'notification_sender_enabled'=>['nullable','boolean'],
        ]);

        $this->validateProviderAddress((string) $data['api_base_url'],(string) $data['endpoint_path']);

        $setting=SmsProviderSetting::query()->firstOrNew([
            'provider'=>'advansys_bulk_sms',
            'environment'=>'production',
        ]);
        $before=$setting->exists?$this->snapshot($setting):null;

        $setting->fill([
            'enabled'=>$request->boolean('enabled'),
            'api_base_url'=>rtrim((string) $data['api_base_url'],'/'),
            'endpoint_path'=>(string) $data['endpoint_path'],
            'default_sender_name'=>trim((string) $data['default_sender_name']),
            'default_country_code'=>(string) $data['default_country_code'],
            'operator_resolution_mode'=>(string) $data['operator_resolution_mode'],
            'request_timeout'=>(int) $data['request_timeout'],
            'retry_count'=>(int) $data['retry_count'],
            'retry_backoff_seconds'=>(int) $data['retry_backoff_seconds'],
            'delivery_logging'=>$request->boolean('delivery_logging'),
            'otp_sender_enabled'=>$request->boolean('otp_sender_enabled'),
            'notification_sender_enabled'=>$request->boolean('notification_sender_enabled'),
        ]);

        $token=trim((string) ($data['api_token'] ?? ''));
        if ($token!=='') {
            $setting->api_token_encrypted=$token;
        }
        if ($request->boolean('enabled') && ! $setting->tokenConfigured()) {
            throw ValidationException::withMessages(['api_token'=>__('sms.errors.token_required')]);
        }

        $setting->save();

        $audit->record('sms_settings.updated',$actor,$setting,$before,$this->snapshot($setting),$request);

        return back()->with('status',__('sms.saved'));
    }

    public function test(Request $request,SmsGateway $gateway,AuditLogger $audit): RedirectResponse
    {
        $actor=$this->authorize($request,'sms_settings.test');
        $data=$request->validate([
            'phone'=>['required','string','max:40'],
            'message'=>['required','string','max:1000'],
            'operator_id'=>['nullable','integer','in:1,2,3,7'],
            'sender'=>['nullable','string','max:64'],
        ]);

        $log=$gateway->send(new SmsMessage(
            recipient:(string) $data['phone'],
            message:(string) $data['message'],
            purpose:'system',
            locale:app()->getLocale(),
            sender:isset($data['sender'])?(string) $data['sender']:null,
            operatorId:isset($data['operator_id'])?(int) $data['operator_id']:null,
            requestId:(string) Str::uuid(),
            correlationId:(string) Str::uuid(),
            createdBy:(int) $actor->id,
            isTest:true,
        ));

        $audit->record('sms.test_sent',$actor,$log,null,[
            'request_id'=>$log->request_id,
            'status'=>$log->status,
            'provider_response_code'=>$log->provider_response_code,
            'recipient'=>$log->recipient_masked,
            'latency_ms'=>$log->latency_ms,
        ],$request);

        $testResult=[
            'status'=>$log->status,
            'meaning'=>$log->status==='sent'
                ? __('sms.provider.sent')
                : ($log->error_message ?: __('sms.provider.unknown_response')),
            'request_id'=>$log->request_id,
            'timestamp'=>$log->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'latency_ms'=>$log->latency_ms ?? 0,
        ];

        if ($log->status!=='sent') {
            return back()
                ->withInput()
                ->with('sms_test_result',$testResult)
                ->withErrors(['sms_test'=>__('sms.test_failed',[
                    'reason'=>$testResult['meaning'],
                    'request'=>$log->request_id,
                ])]);
        }

        return back()
            ->with('sms_test_result',$testResult)
            ->with('status',__('sms.test_sent',[
                'request'=>$log->request_id,
                'latency'=>$log->latency_ms ?? 0,
            ]));
    }

    private function authorizeAny(Request $request): User
    {
        $actor=$request->user();
        abort_unless($actor instanceof User,401);
        abort_unless(
            $actor->hasRole('SUPER_ADMIN')
            || $actor->hasPermission('sms_settings.manage')
            || $actor->hasPermission('sms_settings.test'),
            403,
        );

        return $actor;
    }

    private function authorize(Request $request,string $permission): User
    {
        $actor=$request->user();
        abort_unless($actor instanceof User,401);
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission($permission),403);

        return $actor;
    }

    private function validateProviderAddress(string $base,string $path): void
    {
        $parts=parse_url($base);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? ''))!=='https'
            || strtolower((string) ($parts['host'] ?? ''))!=='hub.advansystelecom.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port']!==443)) {
            throw ValidationException::withMessages(['api_base_url'=>__('sms.errors.invalid_provider_url')]);
        }

        if ($path!=='/generalapiv12/api/bulkSMS/ForwardSMS') {
            throw ValidationException::withMessages(['endpoint_path'=>__('sms.errors.invalid_endpoint')]);
        }
    }

    private function snapshot(SmsProviderSetting $setting): array
    {
        return [
            'provider'=>$setting->provider,
            'enabled'=>$setting->enabled,
            'environment'=>$setting->environment,
            'api_base_url'=>$setting->api_base_url,
            'endpoint_path'=>$setting->endpoint_path,
            'token_configured'=>$setting->tokenConfigured(),
            'default_sender_name'=>$setting->default_sender_name,
            'default_country_code'=>$setting->default_country_code,
            'operator_resolution_mode'=>$setting->operator_resolution_mode,
            'request_timeout'=>$setting->request_timeout,
            'retry_count'=>$setting->retry_count,
            'retry_backoff_seconds'=>$setting->retry_backoff_seconds,
            'delivery_logging'=>$setting->delivery_logging,
            'otp_sender_enabled'=>$setting->otp_sender_enabled,
            'notification_sender_enabled'=>$setting->notification_sender_enabled,
        ];
    }
}
