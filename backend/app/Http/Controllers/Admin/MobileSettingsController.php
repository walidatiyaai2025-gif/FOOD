<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileAppSetting;
use App\Models\MobileStoreSubmission;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\StoreReviewerAccount;
use App\Models\SystemVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DriverLocationEnforcementPolicy;
use App\Services\PushDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MobileSettingsController extends Controller
{
    public function index(Request $request, DriverLocationEnforcementPolicy $driverLocationPolicy): View
    {
        $this->authorizeAny($request);

        $settings = MobileAppSetting::query()->orderBy('app')->orderBy('environment')->get();
        $selectedApp = in_array((string) $request->query('app'), ['customer', 'driver', 'van'], true)
            ? (string) $request->query('app')
            : 'customer';
        $selectedEnvironment = in_array((string) $request->query('environment'), ['development', 'staging', 'production'], true)
            ? (string) $request->query('environment')
            : 'production';
        $selectedSetting = $settings->first(
            fn (MobileAppSetting $setting): bool => $setting->app === $selectedApp
                && $setting->environment === $selectedEnvironment,
        );

        return view('admin.mobile-settings', [
            'settings' => $settings,
            'selectedApp' => $selectedApp,
            'selectedEnvironment' => $selectedEnvironment,
            'selectedSetting' => $selectedSetting,
            'currentReleaseVersion' => $this->currentReleaseVersion(),
            'providers' => PushProviderSetting::query()->orderBy('app')->orderBy('platform')->orderBy('environment')->get(),
            'devices' => PushDeviceToken::query()->with('user:id,name,email')->whereNull('revoked_at')->latest()->limit(100)->get(),
            'logs' => PushDeliveryLog::query()->latest()->limit(100)->get(),
            'driverLocationPolicy' => $driverLocationPolicy->snapshot(),
            'storeSubmissions' => MobileStoreSubmission::query()->orderBy('app')->orderBy('platform')->orderBy('environment')->get(),
            'reviewerAccounts' => StoreReviewerAccount::query()->orderBy('app')->orderBy('platform')->orderBy('persona')->get(),
        ]);
    }

    public function updateApp(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorize($request, 'mobile_settings.manage');
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'environment' => ['required', 'in:development,staging,production'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'android_package_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ios_bundle_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'published_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'published_build' => ['sometimes', 'nullable', 'string', 'max:64'],
            'minimum_supported_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'recommended_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'force_update' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_message_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'maintenance_message_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'google_play_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'app_store_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'privacy_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'terms_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'support_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'delete_account_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'footer_display_mode' => ['sometimes', 'in:persistent,about_only,hidden'],
            'release_notes_ar' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'release_notes_en' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'deep_link_scheme' => ['sometimes', 'nullable', 'string', 'max:64'],
            'deep_link_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'readiness_android' => ['sometimes', 'boolean'],
            'readiness_ios' => ['sometimes', 'boolean'],
            'deep_link_json' => ['sometimes', 'nullable', 'json'],
            'store_readiness_json' => ['sometimes', 'nullable', 'json'],
        ]);

        $before = MobileAppSetting::query()
            ->where('app', $data['app'])
            ->where('environment', $data['environment'])
            ->first();

        if (! $before instanceof MobileAppSetting && trim((string) ($data['display_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'display_name' => [app()->getLocale() === 'ar'
                    ? 'اسم العرض مطلوب عند إنشاء إعداد تطبيق جديد.'
                    : 'Display name is required when creating a new app setting.'],
            ]);
        }

        $values = [];
        foreach ([
            'display_name',
            'android_package_id',
            'ios_bundle_id',
            'published_version',
            'published_build',
            'minimum_supported_version',
            'recommended_version',
            'maintenance_message_ar',
            'maintenance_message_en',
            'google_play_url',
            'app_store_url',
            'privacy_url',
            'terms_url',
            'support_url',
            'delete_account_url',
            'footer_display_mode',
            'release_notes_ar',
            'release_notes_en',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $values[$key] = $data[$key];
            }
        }

        foreach (['force_update', 'maintenance_mode'] as $key) {
            if (array_key_exists($key, $data)) {
                $values[$key] = $request->boolean($key);
            }
        }

        if (array_key_exists('deep_link_scheme', $data) || array_key_exists('deep_link_host', $data)) {
            $deepLinks = is_array($before?->deep_link_config) ? $before->deep_link_config : [];

            foreach (['deep_link_scheme' => 'scheme', 'deep_link_host' => 'host'] as $input => $key) {
                if (! array_key_exists($input, $data)) {
                    continue;
                }

                $value = trim((string) ($data[$input] ?? ''));
                if ($value === '') {
                    unset($deepLinks[$key]);
                } else {
                    $deepLinks[$key] = $value;
                }
            }

            $values['deep_link_config'] = $deepLinks;
        } elseif (array_key_exists('deep_link_json', $data)) {
            $values['deep_link_config'] = $this->decode($data['deep_link_json']);
        }

        if (array_key_exists('readiness_android', $data) || array_key_exists('readiness_ios', $data)) {
            $readiness = is_array($before?->store_readiness) ? $before->store_readiness : [];
            $readiness['android'] = $request->boolean('readiness_android');
            $readiness['ios'] = $request->boolean('readiness_ios');
            $values['store_readiness'] = $readiness;
        } elseif (array_key_exists('store_readiness_json', $data)) {
            $values['store_readiness'] = $this->decode($data['store_readiness_json']);
        }

        $setting = MobileAppSetting::query()->updateOrCreate(
            ['app' => $data['app'], 'environment' => $data['environment']],
            $values,
        );

        $audit->record(
            'mobile_settings.updated',
            $actor,
            $setting,
            $before?->toArray(),
            $setting->toArray(),
            $request,
        );

        return redirect()
            ->route('admin.mobile-settings.index', [
                'app' => $data['app'],
                'environment' => $data['environment'],
            ])
            ->with('status', __('mobile_settings.saved'));
    }

    public function updateDriverLocationPolicy(
        Request $request,
        AuditLogger $audit,
        DriverLocationEnforcementPolicy $policy,
    ): RedirectResponse {
        $actor = $this->authorize($request, 'mobile_settings.manage');
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'freshness_seconds' => ['required', 'integer', 'min:30', 'max:600'],
        ]);

        $enabled = $request->boolean('enabled');
        if ($enabled) {
            $readiness = $policy->rolloutReadiness();
            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'enabled' => [app()->getLocale() === 'ar'
                        ? 'لا يمكن تفعيل فرض الموقع قبل اكتمال سياسة إصدار السائق الداعمة لإرسال الموقع لأندرويد وآي أو إس مع روابط تحديث صالحة.'
                        : 'Driver location enforcement cannot be enabled until Android and iOS Driver policies require a heartbeat-capable version and provide valid update URLs.'],
                ]);
            }
        }

        $before = $policy->snapshot();
        $after = $policy->persist(
            $enabled,
            (int) $data['freshness_seconds'],
        );

        $audit->record(
            'driver.location_enforcement.policy_updated',
            $actor,
            null,
            $before,
            $after,
            $request,
        );

        return back()->with('status', app()->getLocale() === 'ar'
            ? 'تم حفظ سياسة الموقع الإلزامية للسائق.'
            : 'Driver location enforcement policy saved.');
    }

    public function updateProvider(
        Request $request,
        AuditLogger $audit,
        PushDeliveryService $push,
    ): RedirectResponse {
        $actor = $this->authorize($request, 'push_settings.manage');
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'platform' => ['required', 'in:android,ios'],
            'environment' => ['required', 'in:development,staging,production'],
            'enabled' => ['nullable', 'boolean'],
            'credentials_json' => ['nullable', 'json'],
            'default_sound' => ['nullable', 'string', 'max:255'],
            'default_channel' => ['nullable', 'string', 'max:255'],
            'default_icon' => ['nullable', 'string', 'max:255'],
            'default_category' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = PushProviderSetting::query()
            ->where('app', $data['app'])
            ->where('platform', $data['platform'])
            ->where('environment', $data['environment'])
            ->first();

        $credentials = isset($data['credentials_json']) && trim((string) $data['credentials_json']) !== ''
            ? $this->decode($data['credentials_json'])
            : $existing?->credentials_encrypted;

        $provider = PushProviderSetting::query()->updateOrCreate(
            [
                'app' => $data['app'],
                'platform' => $data['platform'],
                'environment' => $data['environment'],
            ],
            [
                'provider' => 'firebase',
                'enabled' => $request->boolean('enabled'),
                'credentials_encrypted' => $credentials,
                'default_sound' => $data['default_sound'] ?? null,
                'default_channel' => $data['default_channel'] ?? null,
                'default_icon' => $data['default_icon'] ?? null,
                'default_category' => $data['default_category'] ?? null,
            ],
        );

        if ($provider->enabled) {
            $push->validateProvider($provider);
        }

        $audit->record(
            'push_settings.updated',
            $actor,
            $provider,
            null,
            [
                'app' => $provider->app,
                'platform' => $provider->platform,
                'environment' => $provider->environment,
                'enabled' => $provider->enabled,
                'provider' => $provider->provider,
            ],
            $request,
        );

        return back()->with('status', __('mobile_settings.push_saved'));
    }

    public function testProvider(
        Request $request,
        AuditLogger $audit,
        PushDeliveryService $push,
    ): RedirectResponse {
        $actor = $this->authorize($request, 'push_settings.manage');
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'platform' => ['required', 'in:android,ios'],
            'environment' => ['required', 'in:development,staging,production'],
        ]);

        $provider = PushProviderSetting::query()
            ->where('app', $data['app'])
            ->where('platform', $data['platform'])
            ->where('environment', $data['environment'])
            ->first();

        if ($provider === null) {
            throw ValidationException::withMessages([
                'credentials_json' => __('mobile_settings.provider_missing'),
            ]);
        }

        $result = $push->testProvider($provider);
        $audit->record(
            'push.provider_tested',
            $actor,
            $provider,
            null,
            [
                'app' => $provider->app,
                'platform' => $provider->platform,
                'environment' => $provider->environment,
                'project_id' => $result['project_id'],
                'auth_mode' => $result['auth_mode'],
            ],
            $request,
        );

        return back()->with('status', app()->getLocale() === 'ar'
            ? 'تم التحقق من اتصال Firebase بنجاح باستخدام '.$result['auth_mode'].'.'
            : 'Firebase authentication verified successfully using '.$result['auth_mode'].'.');
    }

    public function testPush(
        Request $request,
        AuditLogger $audit,
        PushDeliveryService $push,
    ): RedirectResponse {
        $actor = $this->authorize($request, 'push_settings.test');
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:push_device_tokens,id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:1000'],
            'body_en' => ['required', 'string', 'max:1000'],
        ]);

        $device = PushDeviceToken::query()
            ->whereNull('revoked_at')
            ->with('user:id,locale')
            ->findOrFail($data['device_id']);

        $provider = PushProviderSetting::query()
            ->where('app', $device->app)
            ->where('platform', $device->platform)
            ->where('environment', $device->environment)
            ->where('enabled', true)
            ->first();

        if ($provider === null) {
            throw ValidationException::withMessages([
                'device_id' => __('mobile_settings.provider_missing'),
            ]);
        }

        $deviceUser = $device->user;
        $english = $deviceUser instanceof User && $deviceUser->locale === 'en';
        $log = $push->send(
            $provider,
            $device,
            [
                'title' => $english ? $data['title_en'] : $data['title_ar'],
                'body' => $english ? $data['body_en'] : $data['body_ar'],
                'data' => ['test' => '1'],
            ],
            true,
        );

        $audit->record(
            'push.test_sent',
            $actor,
            $log,
            null,
            [
                'device_id' => $device->id,
                'app' => $device->app,
                'platform' => $device->platform,
                'environment' => $device->environment,
                'status' => $log->status,
            ],
            $request,
        );

        if ($log->status !== 'sent') {
            $reason = trim((string) $log->error_message);

            return back()->withErrors([
                'push_test' => (app()->getLocale() === 'ar'
                    ? 'فشل الإشعار التجريبي'
                    : 'Test notification failed')
                    .($reason !== '' ? ': '.$reason : '.'),
            ]);
        }

        return back()->with('status', __('mobile_settings.test_sent'));
    }

    private function authorizeAny(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless(
            $actor->hasPermission('mobile_settings.manage')
            || $actor->hasPermission('push_settings.manage')
            || $actor->hasPermission('push_settings.test'),
            403,
        );

        return $actor;
    }

    private function authorize(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->hasPermission($permission), 403);

        return $actor;
    }

    private function currentReleaseVersion(): string
    {
        $installed = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (is_string($installed) && trim($installed) !== '') {
            return trim($installed);
        }

        $repository = trim((string) @file_get_contents(base_path('../VERSION')));

        return $repository !== '' ? $repository : 'unavailable';
    }

    private function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
