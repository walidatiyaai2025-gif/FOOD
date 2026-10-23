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
            ->flatMap(fn (array $group): array => $group['children'])
            ->pluck('key');

        $canOperationalSettings = $superAdmin
            || $user->hasPermission('settings.view')
            || $user->hasPermission('settings.manage');

        $access = [
            'mobile' => $superAdmin
                || $user->hasPermission('mobile_settings.manage')
                || $user->hasPermission('push_settings.manage')
                || $user->hasPermission('push_settings.test'),
            'app_versions' => $superAdmin || $user->hasPermission('platform.manage'),
            'enterprise_modules' => $superAdmin || $user->hasPermission('platform.manage'),
            'sms' => $superAdmin
                || $user->hasPermission('sms_settings.manage')
                || $user->hasPermission('sms_settings.test'),
            'assistant' => $superAdmin || $canOperationalSettings,
            'translations' => $superAdmin || $user->hasPermission('translations.manage'),
            'foodex_reset' => $superAdmin,
            'production_dataset' => $superAdmin,
            'production_launch' => $superAdmin,
            'system_update' => $superAdmin || $user->hasPermission('system.update'),
            'lookups' => $superAdmin
                || $user->hasPermission('lookups.view')
                || $user->hasPermission('lookups.manage')
                || $visibleNavigationKeys->contains('lookup_management'),
            'system_lookups' => $superAdmin && $user->hasPermission('lookups.manage'),
            'b2b_settings' => $superAdmin || $canOperationalSettings,
            'b2b_storefront' => $superAdmin || $visibleNavigationKeys->contains('b2b_storefront'),
            'b2b_web_storefront' => $superAdmin || $visibleNavigationKeys->contains('b2b_web_storefront'),
            'b2c_settings' => $superAdmin || $canOperationalSettings,
            'b2c_storefront' => $superAdmin || $visibleNavigationKeys->contains('b2c_storefront'),
        ];

        $settingGroups = [
            'apps' => [
                'title' => __('admin.settings_center.groups.apps'),
                'description' => __('admin.settings_center.groups.apps_description'),
                'items' => [
                    ['key' => 'mobile', 'icon' => 'APP', 'title' => __('admin.settings_center.items.mobile'), 'description' => __('admin.settings_center.items.mobile_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'runtime', 'allowed' => $access['mobile']],
                    ['key' => 'driver_location', 'icon' => 'GPS', 'title' => __('admin.settings_center.items.driver_location'), 'description' => __('admin.settings_center.items.driver_location_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'location', 'allowed' => $access['mobile']],
                    ['key' => 'push', 'icon' => 'PUSH', 'title' => __('admin.settings_center.items.push'), 'description' => __('admin.settings_center.items.push_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'push', 'allowed' => $access['mobile']],
                    ['key' => 'push_test', 'icon' => 'TEST', 'title' => __('admin.settings_center.items.push_test'), 'description' => __('admin.settings_center.items.push_test_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'push-test', 'allowed' => $access['mobile']],
                    ['key' => 'publishing', 'icon' => 'STORE', 'title' => __('admin.settings_center.items.publishing'), 'description' => __('admin.settings_center.items.publishing_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'publishing', 'allowed' => $access['mobile']],
                    ['key' => 'reviewers', 'icon' => 'QA', 'title' => __('admin.settings_center.items.reviewers'), 'description' => __('admin.settings_center.items.reviewers_description'), 'route' => 'admin.mobile-settings.index', 'params' => [], 'fragment' => 'reviewers', 'allowed' => $access['mobile']],
                    ['key' => 'app_versions', 'icon' => 'VER', 'title' => __('admin.settings_center.items.app_versions'), 'description' => __('admin.settings_center.items.app_versions_description'), 'route' => 'admin.app-versions.index', 'params' => [], 'fragment' => null, 'allowed' => $access['app_versions']],
                ],
            ],
            'communication' => [
                'title' => __('admin.settings_center.groups.communication'),
                'description' => __('admin.settings_center.groups.communication_description'),
                'items' => [
                    ['key' => 'sms', 'icon' => 'SMS', 'title' => __('admin.settings_center.items.sms'), 'description' => __('admin.settings_center.items.sms_description'), 'route' => 'admin.sms-settings.index', 'params' => [], 'fragment' => null, 'allowed' => $access['sms']],
                    ['key' => 'assistant', 'icon' => 'AI', 'title' => __('admin.settings_center.items.assistant'), 'description' => __('admin.settings_center.items.assistant_description'), 'route' => 'admin.assistant-settings.index', 'params' => [], 'fragment' => null, 'allowed' => $access['assistant']],
                    ['key' => 'translations', 'icon' => 'AR', 'title' => __('admin.settings_center.items.translations'), 'description' => __('admin.settings_center.items.translations_description'), 'route' => 'admin.translations.index', 'params' => [], 'fragment' => null, 'allowed' => $access['translations']],
                ],
            ],
            'business' => [
                'title' => __('admin.settings_center.groups.business'),
                'description' => __('admin.settings_center.groups.business_description'),
                'items' => [
                    ['key' => 'lookups', 'icon' => 'REF', 'title' => __('admin.settings_center.items.lookups'), 'description' => __('admin.settings_center.items.lookups_description'), 'route' => 'admin.lookups.index', 'params' => [], 'fragment' => null, 'allowed' => $access['lookups']],
                    ['key' => 'system_lookups', 'icon' => 'SYS', 'title' => __('admin.settings_center.items.system_lookups'), 'description' => __('admin.settings_center.items.system_lookups_description'), 'route' => 'admin.operations.lookups.index', 'params' => [], 'fragment' => null, 'allowed' => $access['system_lookups']],
                    ['key' => 'b2b_settings', 'icon' => 'B2B', 'title' => __('admin.settings_center.items.b2b_settings'), 'description' => __('admin.settings_center.items.b2b_settings_description'), 'route' => 'admin.b2b.settings-permissions', 'params' => [], 'fragment' => null, 'allowed' => $access['b2b_settings']],
                    ['key' => 'b2b_storefront', 'icon' => 'APP', 'title' => __('admin.settings_center.items.b2b_storefront'), 'description' => __('admin.settings_center.items.b2b_storefront_description'), 'route' => 'admin.b2b.module', 'params' => ['module' => 'storefront'], 'fragment' => null, 'allowed' => $access['b2b_storefront']],
                    ['key' => 'b2b_web_storefront', 'icon' => 'WEB', 'title' => __('admin.settings_center.items.b2b_web_storefront'), 'description' => __('admin.settings_center.items.b2b_web_storefront_description'), 'route' => 'admin.b2b.module', 'params' => ['module' => 'web_storefront'], 'fragment' => null, 'allowed' => $access['b2b_web_storefront']],
                    ['key' => 'b2c_settings', 'icon' => 'B2C', 'title' => __('admin.settings_center.items.b2c_settings'), 'description' => __('admin.settings_center.items.b2c_settings_description'), 'route' => 'admin.b2c.module', 'params' => ['module' => 'settings'], 'fragment' => null, 'allowed' => $access['b2c_settings']],
                    ['key' => 'b2c_storefront', 'icon' => 'WEB', 'title' => __('admin.settings_center.items.b2c_storefront'), 'description' => __('admin.settings_center.items.b2c_storefront_description'), 'route' => 'admin.b2c.module', 'params' => ['module' => 'storefront'], 'fragment' => null, 'allowed' => $access['b2c_storefront']],
                ],
            ],
            'platform' => [
                'title' => __('admin.settings_center.groups.platform'),
                'description' => __('admin.settings_center.groups.platform_description'),
                'items' => [
                    ['key' => 'enterprise_modules', 'icon' => 'MOD', 'title' => __('enterprise_modules.title'), 'description' => __('enterprise_modules.description'), 'route' => 'admin.enterprise-modules.index', 'params' => [], 'fragment' => null, 'allowed' => $access['enterprise_modules']],
                    ['key' => 'foodex_reset', 'icon' => 'RST', 'title' => __('foodex_reset.title'), 'description' => __('foodex_reset.description'), 'route' => 'admin.foodex-reset.index', 'params' => [], 'fragment' => null, 'allowed' => $access['foodex_reset']],
                    ['key' => 'production_dataset', 'icon' => 'DATA', 'title' => __('production_dataset_switch.title'), 'description' => __('production_dataset_switch.description'), 'route' => 'admin.production-dataset-switch.index', 'params' => [], 'fragment' => null, 'allowed' => $access['production_dataset']],
                    ['key' => 'production_launch', 'icon' => 'GO', 'title' => __('production_launch.title'), 'description' => __('production_launch.description'), 'route' => 'admin.production-launch.index', 'params' => [], 'fragment' => null, 'allowed' => $access['production_launch']],
                    ['key' => 'system_update', 'icon' => 'UPD', 'title' => __('admin.settings_center.items.system_update'), 'description' => __('admin.settings_center.items.system_update_description'), 'route' => 'admin.system-update.index', 'params' => [], 'fragment' => null, 'allowed' => $access['system_update']],
                ],
            ],
        ];

        return view('admin.settings-center', [
            'user' => $user,
            'navGroups' => $navGroups,
            'navContext' => 'administration_hub',
            'access' => $access,
            'settingGroups' => $settingGroups,
        ]);
    }
}
