<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AssistantRuntimeSettings;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AssistantSettingsController extends Controller
{
    public function __construct(
        private readonly AssistantRuntimeSettings $settings,
        private readonly AuditLogger $audit,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless(
            $actor->hasPermission('settings.view') || $actor->hasPermission('settings.manage'),
            403,
        );

        return view('admin.assistant-settings', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'assistant_settings',
            'assistantSettings' => $this->settings->snapshot(),
            'canManage' => $actor->hasPermission('settings.manage'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('settings.manage'), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $requestedEnabled = (bool) $validated['enabled'];

        if ($requestedEnabled && ! $this->settings->readOnly()) {
            throw ValidationException::withMessages([
                'enabled' => [
                    app()->getLocale() === 'ar'
                        ? 'لا يمكن تفعيل المساعد لأن سياج القراءة فقط غير مفعل على الخادم.'
                        : 'Assistant cannot be enabled while the server read-only safety fence is disabled.',
                ],
            ]);
        }

        $before = $this->settings->snapshot();
        $setting = $this->settings->persist($requestedEnabled);
        $after = $this->settings->snapshot();

        $this->audit->record(
            'assistant.settings.updated',
            $actor,
            $setting,
            $before,
            $after,
            $request,
        );

        return redirect()
            ->route('admin.assistant-settings.index')
            ->with(
                'status',
                app()->getLocale() === 'ar'
                    ? 'تم حفظ إعدادات مساعد FOODEX.'
                    : 'FOODEX Assistant settings saved.',
            );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
