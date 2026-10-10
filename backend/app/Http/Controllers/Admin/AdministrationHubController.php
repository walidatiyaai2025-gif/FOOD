<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MobileReleaseArtifactMirror;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

final class AdministrationHubController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly MobileReleaseArtifactMirror $mobileArtifacts,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $locale = in_array($user->locale, ['ar', 'en'], true)
            ? $user->locale
            : (string) config('app.locale', 'ar');

        App::setLocale($locale);

        $mobileReleaseVersions = $this->mobileArtifacts->currentAppVersions();
        $mobileReleaseArtifacts = collect(array_keys($mobileReleaseVersions))
            ->map(fn (string $app): array => $this->mobileArtifacts->statusPayloadForApp($app))
            ->keyBy('app');

        return view('admin.administration-hub', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'administration_hub',
            'canAppPreview' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('app_preview.view'),
            'canPlatformManage' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('platform.manage'),
            'canSecurity' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('security.view'),
            'canFinanceSupport' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('finance.view'),
            'canMobileSettings' => $user->hasRole('SUPER_ADMIN')
                || $user->hasPermission('mobile_settings.manage')
                || $user->hasPermission('push_settings.manage')
                || $user->hasPermission('push_settings.test'),
            'canSmsSettings' => $user->hasRole('SUPER_ADMIN')
                || $user->hasPermission('sms_settings.manage')
                || $user->hasPermission('sms_settings.test'),
            'canTranslations' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('translations.manage'),
            'canAssistantSettings' => $user->hasRole('SUPER_ADMIN')
                || $user->hasPermission('settings.view')
                || $user->hasPermission('settings.manage'),
            'canSystemUpdate' => $user->hasRole('SUPER_ADMIN') || $user->hasPermission('system.update'),
            'mobileReleaseVersion' => $mobileReleaseVersions['customer'] ?? null,
            'mobileReleaseVersions' => $mobileReleaseVersions,
            'mobileReleaseArtifacts' => $mobileReleaseArtifacts,
        ]);
    }
}
