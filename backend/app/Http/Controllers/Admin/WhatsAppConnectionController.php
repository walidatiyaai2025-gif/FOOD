<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ConnectionHealthRecorder;
use App\Domain\WhatsApp\ConnectionProfileManager;
use App\Domain\WhatsApp\ModuleState;
use App\Domain\WhatsApp\OutboundMessage;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\WhatsAppProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class WhatsAppConnectionController extends Controller
{
    public function index(Request $request, RuntimePolicy $policy): View
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.view'), 403);

        return view('admin.whatsapp-connections', [
            'moduleState' => $policy->state(),
            'profiles' => WhatsAppConnectionProfile::query()
                ->orderByDesc('is_primary')
                ->orderByDesc('updated_at')
                ->get(),
            'canManage' => $actor->hasPermission('whatsapp.settings'),
            'canDangerous' => $actor->hasPermission('whatsapp.settings') && $actor->hasRole('SUPER_ADMIN'),
            'transportHealth' => $this->transportHealth(),
        ]);
    }

    public function store(Request $request, ConnectionProfileManager $manager): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.settings'), 403);
        $attributes = $this->connectionInput($request);
        $secrets = $request->validate([
            'credential_secret' => ['nullable', 'string', 'max:10000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:10000'],
            'webhook_signing_secret' => ['nullable', 'string', 'max:10000'],
        ]);

        $manager->create(
            $actor,
            $attributes,
            isset($secrets['credential_secret']) ? (string) $secrets['credential_secret'] : null,
            isset($secrets['webhook_verify_token']) ? (string) $secrets['webhook_verify_token'] : null,
            isset($secrets['webhook_signing_secret']) ? (string) $secrets['webhook_signing_secret'] : null,
        );

        return $this->back(__('whatsapp.connections.created'));
    }

    public function update(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.settings'), 403);
        $manager->update($actor, $profile, $this->connectionInput($request));

        return $this->back(__('whatsapp.connections.updated'));
    }

    public function replaceCredential(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $request->validate([
            'credential_secret' => ['nullable', 'string', 'max:10000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:10000'],
            'webhook_signing_secret' => ['nullable', 'string', 'max:10000'],
        ]);
        $manager->replaceCredential(
            $actor,
            $profile,
            isset($data['credential_secret']) ? (string) $data['credential_secret'] : null,
            isset($data['webhook_verify_token']) ? (string) $data['webhook_verify_token'] : null,
            isset($data['webhook_signing_secret']) ? (string) $data['webhook_signing_secret'] : null,
        );

        return $this->back(__('whatsapp.connections.credential_replaced'));
    }

    public function validateConnection(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.settings'), 403);
        $valid = $manager->validate($actor, $profile);

        return $this->back(__($valid ? 'whatsapp.connections.validation_ok' : 'whatsapp.connections.validation_failed'));
    }

    public function sendTestMessage(
        Request $request,
        WhatsAppConnectionProfile $profile,
        WhatsAppProviderRegistry $providers,
        ConnectionHealthRecorder $health,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.settings'), 403);

        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $recipient = $this->normalizeTestRecipient((string) $data['recipient']);
        $message = trim((string) $data['message']);

        try {
            $result = $providers->forProfile($profile)->send(
                $profile,
                new OutboundMessage(
                    recipient: $recipient,
                    kind: 'text',
                    body: $message,
                    metadata: ['source' => 'dashboard_connection_test'],
                    idempotencyKey: 'whatsapp-connection-test:'
                        .(int) $profile->getKey().':'.(string) Str::uuid(),
                ),
            );
            $health->outboundSucceeded($profile);
        } catch (Throwable $exception) {
            $health->providerError($profile, $exception->getMessage());

            throw ValidationException::withMessages([
                'recipient' => [app()->getLocale() === 'ar'
                    ? 'فشل إرسال رسالة واتساب التجريبية: '.$exception->getMessage()
                    : 'WhatsApp test message failed: '.$exception->getMessage()],
            ]);
        }

        $audit->record(
            'whatsapp.connection_test_message_sent',
            $actor,
            $profile,
            null,
            [
                'recipient' => $this->maskPhone($recipient),
                'provider_message_id' => $result->providerMessageId,
                'provider' => (string) $profile->provider_type,
            ],
            $request,
        );

        return $this->back(app()->getLocale() === 'ar'
            ? 'تم إرسال رسالة واتساب التجريبية إلى '.$recipient.'.'
            : 'WhatsApp test message sent to '.$recipient.'.');
    }

    public function switchPrimary(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $request->validate([
            'old_profile_action' => ['required', Rule::in(['drain', 'inactive'])],
            'confirm' => ['accepted'],
        ]);
        $manager->switchPrimary($actor, $profile, (string) $data['old_profile_action']);

        return $this->back(__('whatsapp.connections.primary_switched'));
    }

    public function receiveOnly(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $manager->moveToReceiveOnly($actor, $profile);

        return $this->back(__('whatsapp.connections.moved_to_receive_only'));
    }

    public function deactivate(
        Request $request,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileManager $manager,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $manager->deactivate($actor, $profile);

        return $this->back(__('whatsapp.connections.deactivated'));
    }

    /** @return array<string, mixed> */
    private function connectionInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'display_phone_number' => ['required', 'string', 'max:32'],
            'normalized_phone_number' => ['nullable', 'string', 'max:24'],
            'provider_type' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i'],
            'provider_phone_number_id' => ['nullable', 'string', 'max:128'],
            'provider_account_id' => ['nullable', 'string', 'max:128'],
            'provider_app_id' => ['nullable', 'string', 'max:128'],
            'provider_metadata' => ['nullable', 'string', 'max:10000', 'json'],
            'webhook_metadata' => ['nullable', 'string', 'max:10000', 'json'],
        ]);

        $data['provider_metadata'] = $this->decodedJson($data['provider_metadata'] ?? null);
        $data['webhook_metadata'] = $this->decodedJson($data['webhook_metadata'] ?? null);

        return $data;
    }

    /** @return array<string, mixed> */
    private function decodedJson(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['provider_metadata' => __('whatsapp.connections.invalid_metadata')]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['provider_metadata' => __('whatsapp.connections.invalid_metadata')]);
        }

        return $decoded;
    }

    /** @return array<string, int>|null */
    private function transportHealth(): ?array
    {
        try {
            return [
                'outbound_retry' => DB::table('whats_app_outbound_dispatches')->where('status', 'retry_wait')->count(),
                'outbound_failed' => DB::table('whats_app_outbound_dispatches')->where('status', 'failed')->count(),
                'webhook_retry' => DB::table('whats_app_webhook_events')->where('status', 'retry_wait')->count(),
                'webhook_failed' => DB::table('whats_app_webhook_events')->where('status', 'failed')->count(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }

    private function normalizeTestRecipient(string $number): string
    {
        $normalized = preg_replace('/[\s().-]+/', '', trim($number)) ?? '';

        if (str_starts_with($normalized, '00')) {
            $normalized = '+'.substr($normalized, 2);
        } elseif (preg_match('/^01[0-9]{9}$/D', $normalized) === 1) {
            $normalized = '+2'.$normalized;
        } elseif (preg_match('/^201[0-9]{9}$/D', $normalized) === 1) {
            $normalized = '+'.$normalized;
        }

        if (! ModuleState::validNumber($normalized)) {
            throw ValidationException::withMessages([
                'recipient' => [app()->getLocale() === 'ar'
                    ? 'رقم واتساب غير صالح. اكتب رقمًا دوليًا مثل +201012345678 أو رقمًا مصريًا يبدأ بـ 01.'
                    : 'Invalid WhatsApp number. Use E.164 such as +201012345678 or an Egyptian mobile beginning with 01.'],
            ]);
        }

        return $normalized;
    }

    private function maskPhone(string $number): string
    {
        $visible = min(4, max(0, strlen($number) - 1));

        return str_repeat('*', max(0, strlen($number) - $visible)).substr($number, -$visible);
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.whatsapp.connections.index')->with('status', $message);
    }
}
