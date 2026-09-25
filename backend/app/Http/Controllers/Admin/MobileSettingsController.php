<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileAppSetting;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PushDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MobileSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAny($request);

        return view('admin.mobile-settings', [
            'settings' => MobileAppSetting::query()->orderBy('app')->orderBy('environment')->get(),
            'providers' => PushProviderSetting::query()->orderBy('app')->orderBy('platform')->orderBy('environment')->get(),
            'devices' => PushDeviceToken::query()->whereNull('revoked_at')->latest()->limit(100)->get(),
            'logs' => PushDeliveryLog::query()->latest()->limit(100)->get(),
        ]);
    }

    public function updateApp(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorize($request, 'mobile_settings.manage');
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver'],
            'environment' => ['required', 'in:development,staging,production'],
            'display_name' => ['required', 'string', 'max:255'],
            'android_package_id' => ['nullable', 'string', 'max:255'],
            'ios_bundle_id' => ['nullable', 'string', 'max:255'],
            'published_version' => ['nullable', 'string', 'max:64'],
            'published_build' => ['nullable', 'string', 'max:64'],
            'minimum_supported_version' => ['nullable', 'string', 'max:64'],
            'recommended_version' => ['nullable', 'string', 'max:64'],
            'force_update' => ['nullable', 'boolean'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'maintenance_message_ar' => ['nullable', 'string', 'max:5000'],
            'maintenance_message_en' => ['nullable', 'string', 'max:5000'],
            'google_play_url' => ['nullable', 'url', 'max:2048'],
            'app_store_url' => ['nullable', 'url', 'max:2048'],
            'privacy_url' => ['nullable', 'url', 'max:2048'],
            'terms_url' => ['nullable', 'url', 'max:2048'],
            'support_url' => ['nullable', 'url', 'max:2048'],
            'release_notes_ar' => ['nullable', 'string', 'max:10000'],
            'release_notes_en' => ['nullable', 'string', 'max:10000'],
            'deep_link_json' => ['nullable', 'json'],
            'store_readiness_json' => ['nullable', 'json'],
        ]);

        $before = MobileAppSetting::query()
            ->where('app', $data['app'])
            ->where('environment', $data['environment'])
            ->first();

        $values = collect($data)
            ->except(['app', 'environment', 'deep_link_json', 'store_readiness_json'])
            ->all();
        $values['force_update'] = $request->boolean('force_update');
        $values['maintenance_mode'] = $request->boolean('maintenance_mode');
        $values['deep_link_config'] = $this->decode($data['deep_link_json'] ?? null);
        $values['store_readiness'] = $this->decode($data['store_readiness_json'] ?? null);

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

        return back()->with('status', __('mobile_settings.saved'));
    }

    public function updateProvider(
        Request $request,
        AuditLogger $audit,
        PushDeliveryService $push,
    ): RedirectResponse {
        $actor = $this->authorize($request, 'push_settings.manage');
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver'],
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
                'provider' => $data['platform'] === 'android' ? 'firebase' : 'apns',
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

        $english = $device->user?->locale === 'en';
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

        return back()->with(
            'status',
            $log->status === 'sent'
                ? __('mobile_settings.test_sent')
                : __('mobile_settings.test_failed'),
        );
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

    private function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
