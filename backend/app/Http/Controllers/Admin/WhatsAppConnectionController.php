<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ConnectionProfileManager;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

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
        ]);
    }

    public function store(Request $request, ConnectionProfileManager $manager): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.settings'), 403);
        $attributes = $this->connectionInput($request);
        $secret = $request->validate([
            'credential_secret' => ['nullable', 'string', 'max:10000'],
        ])['credential_secret'] ?? null;

        $manager->create($actor, $attributes, is_string($secret) ? $secret : null);

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
        $data = $request->validate(['credential_secret' => ['required', 'string', 'max:10000']]);
        $manager->replaceCredential($actor, $profile, $data['credential_secret']);

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
        $manager->switchPrimary($actor, $profile, $data['old_profile_action']);

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

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.whatsapp.connections.index')->with('status', $message);
    }
}
