<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\Lifecycle;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\StateManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WhatsAppFoundationController extends Controller
{
    public function index(Request $request, RuntimePolicy $policy): View
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('whatsapp.view'), 403);

        return view('admin.whatsapp-foundation', [
            'state' => $policy->state(), 'certified' => $policy->evidenceReference() !== null,
            'capabilities' => Capability::cases(), 'canActivate' => $policy->permitsActivation($actor),
        ]);
    }

    public function update(Request $request, StateManager $manager): RedirectResponse
    {
        $actor = $this->actor($request);
        $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['configure', 'lifecycle', 'emergency', 'activate'])],
            'certification' => ['prohibited'], 'evidence' => ['prohibited'],
        ]);
        $revision = $request->integer('revision');
        switch ($request->string('action')->toString()) {
            case 'configure':
                $data = $request->validate([
                    'capabilities' => ['present', 'array:'.implode(',', array_column(Capability::cases(), 'value'))],
                    'capabilities.*' => ['required', 'boolean'],
                    'test_numbers' => ['nullable', 'string', 'max:2000'],
                    'lifecycle' => ['prohibited'], 'emergency_stop' => ['prohibited'],
                ]);
                $numbers = preg_split('/\R/', trim((string) ($data['test_numbers'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $manager->configure($actor, array_map(static fn ($value): bool => (bool) $value, $data['capabilities']), $numbers, $revision);
                break;
            case 'lifecycle':
                $request->validate(['lifecycle' => ['required', Rule::enum(Lifecycle::class)], 'confirm' => ['accepted']]);
                $manager->transition($actor, Lifecycle::from($request->string('lifecycle')->toString()), $revision);
                break;
            case 'emergency':
                $request->validate(['emergency_stop' => ['required', 'boolean'], 'confirm' => ['required_if:emergency_stop,0', 'accepted']]);
                $manager->setEmergencyStop($actor, $request->boolean('emergency_stop'), $revision);
                break;
            case 'activate':
                $request->validate(['confirm' => ['accepted']]);
                $manager->activate($actor, $revision);
                break;
        }

        return redirect()->route('admin.whatsapp.index')->with('status', __('whatsapp.saved'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }
}
