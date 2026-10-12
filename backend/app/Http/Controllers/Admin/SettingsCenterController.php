<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

final class SettingsCenterController extends Controller
{
    public function __construct(private readonly AdminNavigation $navigation) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $locale = in_array($user->locale, ['ar', 'en'], true)
            ? $user->locale
            : (string) config('app.locale', 'ar');

        App::setLocale($locale);

        $superAdmin = $user->hasRole('SUPER_ADMIN');
        $navGroups = $this->navigation->groupsFor($user);
        $visibleNavigationKeys = collect($navGroups)
            ->flatMap(fn (array $group): array => $group['children'] ?? [])
            ->pluck('key');

        $canOperationalSettings = $superAdmin
            || $user->hasPermission('settings.view')
            || $user->hasPermission('settings.manage');

        return view('admin.settings-center', [
            'user' => $user,
            'navGroups' => $navGroups,
            'navContext' => 'administration_hub',
            'access' => [
                'mobile' => $superAdmin
                    || $user->hasPermission('mobile_settings.manage')
                    || $user->hasPermission('push_settings.manage')
                    || $user->hasPermission('push_settings.test'),
                'app_versions' => $superAdmin || $user->hasPermission('platform.manage'),
                'sms' => $superAdmin
                    || $user->hasPermission('sms_settings.manage')
                    || $user->hasPermission('sms_settings.test'),
                'assistant' => $superAdmin || $canOperationalSettings,
                'translations' => $superAdmin || $user->hasPermission('translations.manage'),
                'system_update' => $superAdmin || $user->hasPermission('system.update'),
                'lookups' => $superAdmin
                    || $user->hasPermission('lookups.view')
                    || $user->hasPermission('lookups.manage')
                    || $visibleNavigationKeys->contains('lookup_management'),
                'system_lookups' => $superAdmin && $user->hasPermission('lookups.manage'),
                'b2b_settings' => $superAdmin || $canOperationalSettings,
                'b2b_storefront' => $superAdmin || $visibleNavigationKeys->contains('b2b_storefront'),
                'b2c_settings' => $superAdmin || $canOperationalSettings,
                'b2c_storefront' => $superAdmin || $visibleNavigationKeys->contains('b2c_storefront'),
            ],
        ]);
    }
}
