<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardUiComplianceTest extends TestCase
{
    public function test_order_management_uses_compact_ellipsis_row_actions(): void
    {
        $view = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-order-row-actions', $view);
        $this->assertStringContainsString('>⋮</summary>', $view);
        $this->assertStringContainsString('View order', $view);
        $this->assertStringNotContainsString('<td><div class="actions">', $view);
        $this->assertStringNotContainsString('store_id={{ $detail[\'store_id\'] }}', $view);
        $this->assertStringNotContainsString('channel={{ $detail[\'channel\'] }}', $view);
        $this->assertStringContainsString('$businessLabel', $view);
        $this->assertStringNotContainsString('$driver->id', $view);
        $this->assertStringNotContainsString('#{{ $assignment[\'id\'] }}', $view);
        $this->assertStringNotContainsString('{{ $event[\'reason_code\'] }}', $view);
    }


    public function test_live_tracking_uses_authorized_store_lookup_instead_of_raw_store_id(): void
    {
        $view = file_get_contents(resource_path('views/admin/_driver-live-map.blade.php'));
        $script = file_get_contents(public_path('assets/admin/driver-live-map.js'));

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertStringContainsString('<select data-live-map="store">', $view);
        $this->assertStringContainsString('data-driver-live-map-stores', $view);
        $this->assertStringNotContainsString("driver_live_tracking.store_id') }}<input data-live-map=\"store\"", $view);
        $this->assertStringContainsString('storeLabel(row)', $script);
        $this->assertStringNotContainsString("i18n.store+' '+(row.store_id", $script);
    }

    public function test_reports_render_business_breakdowns_instead_of_raw_json(): void
    {
        $view = file_get_contents(resource_path('views/admin/reports.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-report-breakdowns', $view);
        $this->assertStringContainsString('data-report-breakdown="{{ $extra }}"', $view);
        $this->assertStringContainsString('$reportLabel', $view);
        $this->assertStringNotContainsString('json_encode($data[$extra]', $view);
        $this->assertStringNotContainsString('<pre style="white-space:pre-wrap;margin:0">', $view);
    }

    public function test_customer_360_hides_internal_ids_and_uses_business_labels(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('$businessLabel', $view);
        $this->assertStringNotContainsString('<p>#{{ $customer->id }}', $view);
        $this->assertStringNotContainsString("رقم الفاتورة الداخلي", $view);
        $this->assertStringNotContainsString("Invoice ID", $view);
        $this->assertStringContainsString('<select name="invoice_id">', $view);
        $this->assertStringContainsString('{{ $invoice[\'number\'] }}', $view);
        $this->assertStringNotContainsString('{{ strtoupper($order[\'channel\']) }}', $view);
        $this->assertStringNotContainsString('<td>{{ $order[\'status\'] }}</td>', $view);
    }

    public function test_notifications_use_shared_foodex_admin_shell(): void
    {
        $view = file_get_contents(resource_path('views/admin/notifications.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('foodex-admin-layout', $view);
        $this->assertStringContainsString("@include('admin._sidebar'", $view);
        $this->assertStringContainsString('foodex-admin-main', $view);
    }

    public function test_mobile_settings_expose_customer_driver_and_van_as_first_class_apps(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('value="customer"', $view);
        $this->assertStringContainsString('value="driver"', $view);
        $this->assertStringContainsString('value="van"', $view);
        $this->assertGreaterThanOrEqual(2, substr_count($view, 'value="van"'), 'Van must be available in both runtime and push-provider administration.');
        $this->assertStringContainsString("@selected($selectedApp==='van')", $view);
    }


    public function test_notification_campaigns_hide_internal_identifiers_from_business_users(): void
    {
        $view = file_get_contents(resource_path('views/admin/notification-campaigns.blade.php'));

        $this->assertIsString($view);
        $this->assertStringNotContainsString('#{{ $campaign->id }}', $view);
        $this->assertStringNotContainsString('<td>{{ $run->id }}</td>', $view);
        $this->assertStringNotContainsString("'#'.$run->notification_id", $view);
        $this->assertStringNotContainsString('{{ $run->error_code ??', $view);
        $this->assertStringNotContainsString('<td>{{ $run->status }}</td>', $view);
        $this->assertStringContainsString('notifications.run_status.$run->status', $view);
        $this->assertStringContainsString('{{ $loop->iteration }}', $view);
    }


    public function test_order_operations_shell_copy_uses_locale_catalog(): void
    {
        $view = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('order_operations.title')", $view);
        $this->assertStringContainsString("__('order_operations.filters.channel')", $view);
        $this->assertStringContainsString("__('order_operations.columns.actions')", $view);
        $this->assertStringNotContainsString("$isAr?'إدارة الطلبات':'Order Management'", $view);
        $this->assertStringNotContainsString("$isAr?'إجراءات الطلب':'Order actions'", $view);
    }


    public function test_customer_360_shell_copy_uses_locale_catalog(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('customer_360.title')", $view);
        $this->assertStringContainsString("__('customer_360.sections')", $view);
        $this->assertStringContainsString("__('customer_360.tabs.addresses')", $view);
        $this->assertStringContainsString("customer_360.business_labels.", $view);
        $this->assertStringNotContainsString("$ar?'تفاصيل العميل':'Customer details'", $view);
        $this->assertStringNotContainsString("$ar ? 'الجملة' : 'Wholesale'", $view);
    }


    public function test_customer_360_finance_copy_uses_locale_catalog(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('customer_360.finance.current_balance')", $view);
        $this->assertStringContainsString("__('customer_360.finance.entry_type')", $view);
        $this->assertStringContainsString("__('customer_360.finance.record')", $view);
        $this->assertStringNotContainsString("$ar?'الرصيد الحالي':'Current balance'", $view);
        $this->assertStringNotContainsString("$ar?'تسجيل حركة مالية':'Record financial entry'", $view);
    }


    public function test_customer_360_identity_copy_uses_locale_catalog(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('customer_360.identity.name')", $view);
        $this->assertStringContainsString("__('customer_360.identity.registration_source')", $view);
        $this->assertStringNotContainsString("$ar?'الاسم':'Name'", $view);
        $this->assertStringNotContainsString("$ar?'مصدر التسجيل':'Registration source'", $view);
    }


    public function test_customer_360_related_records_use_locale_catalog(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('customer_360.records.no_orders')", $view);
        $this->assertStringContainsString("__('customer_360.records.manage_order')", $view);
        $this->assertStringContainsString("__('customer_360.records.no_invoices')", $view);
        $this->assertStringNotContainsString("$ar?'لا توجد طلبات داخل النطاق الحالي.':'No orders in the current scope.'", $view);
        $this->assertStringNotContainsString("$ar?'التفاصيل':'Details'", $view);
    }


    public function test_customer_360_has_no_inline_bilingual_user_copy(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("__('customer_360.addresses.map_title')", $view);
        $this->assertStringContainsString("__('customer_360.records.manage_order')", $view);
        $this->assertStringNotContainsString("$ar?'", $view);
        $this->assertStringNotContainsString("$ar ? '", $view);
    }

    public function test_notifications_use_business_user_lookup_instead_of_raw_ids(): void
    {
        $view = file_get_contents(resource_path('views/admin/notifications.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/NotificationController.php'));

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString('name="user_id"><select', str_replace(["\n", "\r"], '', $view));
        $this->assertStringContainsString('$userTargets', $view);
        $this->assertStringNotContainsString('type="number" min="1"', $view);
        $this->assertStringNotContainsString('<strong>#{{ $notification->id }}</strong>', $view);
        $this->assertStringContainsString("select(['id', 'name', 'email'])", $controller);
    }


    public function test_owned_dashboard_views_use_shared_foodex_shell_contract(): void
    {
        $views = [
            'administration-hub.blade.php',
            'customer-360-show.blade.php',
            'driver-live-tracking.blade.php',
            'mobile-settings.blade.php',
            'notification-campaigns.blade.php',
            'notifications.blade.php',
            'order-operations.blade.php',
            'reports.blade.php',
            'van-finance-support.blade.php',
        ];

        foreach ($views as $viewName) {
            $view = file_get_contents(resource_path('views/admin/'.$viewName));

            $this->assertIsString($view, $viewName);
            $this->assertStringContainsString('foodex-admin-layout', $view, $viewName);
            $this->assertStringContainsString('foodex-admin-main', $view, $viewName);
            $this->assertStringContainsString("admin._sidebar", $view, $viewName);
            $this->assertStringContainsString('foodex-page-header', $view, $viewName);
        }
    }


    public function test_notification_campaigns_use_business_user_lookup(): void
    {
        $view = file_get_contents(resource_path('views/admin/notification-campaigns.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("notifications.choose_user", $view);
        $this->assertStringContainsString('$targetUser->name', $view);
        $this->assertStringContainsString('$targetUser->email', $view);
        $this->assertStringNotContainsString('name="user_id" type="number"', $view);
        $this->assertStringNotContainsString('User ID - optional', $view);
        $this->assertStringNotContainsString('رقم المستخدم - اختياري', $view);
    }

    public function test_store_submission_and_reviewer_admin_treat_van_as_first_class_app(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/StoreSubmissionController.php'));

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString("@foreach(['customer','driver','van'] as \$submissionApp)", $view);
        $this->assertStringContainsString('<option value="van">{{ __(\'mobile_settings.apps.van\') }}</option>', $view);
        $this->assertStringContainsString("'van'=>'com.foodex.van'", $view);
        $this->assertSame(2, substr_count($controller, "'app' => ['required', 'in:customer,driver,van']"));
        $this->assertStringContainsString("if (\$reviewer->app === 'van')", $controller);
        $this->assertStringContainsString("hasPermission('van.login')", $controller);
    }


    public function test_mobile_settings_hide_raw_push_identifiers(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("mobile_settings.unknown_user", $view);
        $this->assertStringContainsString("mobile_settings.delivery_status.", $view);
        $this->assertStringContainsString("mobile_settings.delivery_issue", $view);
        $this->assertStringNotContainsString('#{{ $d->id }}', $view);
        $this->assertStringNotContainsString('#{{ $log->id }}', $view);
        $this->assertStringNotContainsString('{{ $log->error_code }}', $view);
        $this->assertStringNotContainsString('HTTP {{ $log->response_code }}', $view);
    }

    public function test_mobile_settings_replace_routine_json_editors_with_structured_controls(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/MobileSettingsController.php'));

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString('name="deep_link_scheme"', $view);
        $this->assertStringContainsString('name="deep_link_host"', $view);
        $this->assertStringContainsString('name="readiness_android"', $view);
        $this->assertStringContainsString('name="readiness_ios"', $view);
        $this->assertStringNotContainsString('name="deep_link_json"', $view);
        $this->assertStringNotContainsString('name="store_readiness_json"', $view);
        $this->assertStringContainsString("'deep_link_scheme' => ['sometimes', 'nullable', 'string', 'max:64']", $controller);
        $this->assertStringContainsString("\$readiness['android'] = \$request->boolean('readiness_android');", $controller);
    }


    public function test_mobile_settings_runtime_readiness_is_structured(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('name="deep_link_scheme"', $view);
        $this->assertStringContainsString('name="deep_link_host"', $view);
        $this->assertStringContainsString('name="readiness_android"', $view);
        $this->assertStringContainsString('name="readiness_ios"', $view);
        $this->assertStringContainsString('name="readiness_privacy"', $view);
        $this->assertStringNotContainsString('name="deep_link_json"', $view);
        $this->assertStringNotContainsString('name="store_readiness_json"', $view);
    }


    public function test_mobile_settings_reviewer_context_uses_business_controls(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('name="reviewer_channel"', $view);
        $this->assertStringContainsString('name="reviewer_store_id"', $view);
        $this->assertStringContainsString('mobile_settings.reviewer_store', $view);
        $this->assertStringNotContainsString('name="context_json"', $view);
        $this->assertStringNotContainsString('Store / tenant / customer / driver / van context JSON', $view);
    }


    public function test_mobile_store_submission_uses_structured_checklists(): void
    {
        $view = file_get_contents(resource_path('views/admin/mobile-settings.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('name="asset_icon_master"', $view);
        $this->assertStringContainsString('name="asset_splash_master"', $view);
        $this->assertStringContainsString('name="asset_screenshots"', $view);
        $this->assertStringContainsString('name="asset_promotional_assets"', $view);
        $this->assertStringContainsString('name="permission_declarations_text"', $view);
        $this->assertStringContainsString('name="privacy_checklist_text"', $view);
        $this->assertStringContainsString('name="manual_gaps_text"', $view);
        $this->assertStringNotContainsString('name="asset_checklist_json"', $view);
        $this->assertStringNotContainsString('name="permission_declarations_json"', $view);
        $this->assertStringNotContainsString('name="privacy_checklist_json"', $view);
        $this->assertStringNotContainsString('name="manual_gaps_json"', $view);
        $this->assertStringContainsString('$submissionMasterAssetStates = [', $view);
        $this->assertStringContainsString('$submissionUploadAssetStates = [', $view);
        $this->assertStringContainsString('name="asset_icon_master"><option value="">—</option>@foreach($submissionMasterAssetStates', $view);
        $this->assertStringContainsString('name="asset_screenshots"><option value="">—</option>@foreach($submissionUploadAssetStates', $view);
        $this->assertStringNotContainsString('$submissionAssetStates = [', $view);
    }


    public function test_owned_dashboard_views_do_not_expose_routine_raw_identifiers_or_json(): void
    {
        $views = [
            'administration-hub.blade.php',
            'customer-360-show.blade.php',
            'driver-live-tracking.blade.php',
            'mobile-settings.blade.php',
            'notification-campaigns.blade.php',
            'notifications.blade.php',
            'order-operations.blade.php',
            'reports.blade.php',
            'van-finance-support.blade.php',
        ];

        foreach ($views as $viewName) {
            $view = file_get_contents(resource_path('views/admin/'.$viewName));

            $this->assertIsString($view, $viewName);

            // Internal IDs may back named <select> lookups, but routine numeric-ID entry must never return.
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]+name="[^"]*_id"[^>]+type="number"|<input[^>]+type="number"[^>]+name="[^"]*_id"/i',
                $view,
                $viewName,
            );

            // Firebase Service Account credentials are the one intentional technical JSON exception.
            $withoutCredentialJson = str_replace('name="credentials_json"', 'name="credentials_payload"', $view);
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:textarea|input)[^>]+name="[^"]*_json"/i',
                $withoutCredentialJson,
                $viewName,
            );

            $this->assertDoesNotMatchRegularExpression(
                '/>\s*(?:User|Store|Driver|Order|Invoice|Campaign|Notification|Device|Log) ID\s*</i',
                $view,
                $viewName,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/#\s*{{\s*\$[^}]*->id\s*}}/',
                $view,
                $viewName,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/{{\s*\$[^}]*->(?:error_code|response_code)\s*}}/',
                $view,
                $viewName,
            );
        }
    }


    public function test_notification_surfaces_follow_record_action_contract(): void
    {
        $campaigns = file_get_contents(resource_path('views/admin/notification-campaigns.blade.php'));
        $notifications = file_get_contents(resource_path('views/admin/notifications.blade.php'));

        $this->assertIsString($campaigns);
        $this->assertIsString($notifications);

        $this->assertStringContainsString("__('notifications.edit_campaign')", $campaigns);
        $this->assertStringContainsString('data-notification-campaign-actions', $campaigns);
        $this->assertStringContainsString("__('notifications.actions')", $campaigns);
        $this->assertStringContainsString('background:var(--foodex-green)', $campaigns);
        $this->assertStringContainsString('background:#fff;color:var(--foodex-green-dark)', $campaigns);

        $this->assertStringContainsString("__('notifications.edit_notification')", $notifications);
        $this->assertStringContainsString('data-notification-edit', $notifications);
        $this->assertStringContainsString('data-notification-row-actions', $notifications);
        $this->assertStringContainsString("__('notifications.actions')", $notifications);
        $this->assertStringContainsString('background:var(--foodex-green)', $notifications);
        $this->assertStringContainsString('background:#fff;color:var(--foodex-green-dark)', $notifications);

        $this->assertStringNotContainsString(
            '<div class="actions" style="margin-top:12px">',
            $campaigns,
        );
    }


    public function test_van_dashboard_parity_contract_covers_1035_owned_domains(): void
    {
        $contract = file_get_contents(base_path('../docs/design-reference/VAN_DASHBOARD_PARITY_CONTRACT.md'));

        $this->assertIsString($contract);
        foreach ([
            'Status: OWNER APPROVED',
            '/admin/customer-360',
            '/admin/operations/orders',
            '/admin/van-finance-support',
            'Notification Center + Campaigns + Push Settings',
            'Wallet/Collection/Receipt/Remittance visibility/audit/support',
            '#1035 / #1036',
            '#1037 Field Operations',
            'Finance mutation ownership is not duplicated',
            '#1042 performs integrated Van ↔ Dashboard runtime verification',
        ] as $required) {
            $this->assertStringContainsString($required, $contract);
        }

        foreach (['Wallet', 'Collection', 'Receipt', 'Remittance', 'Notifications', 'Profile & Settings'] as $surface) {
            $this->assertStringContainsString('| '.$surface.' |', $contract);
        }

        $this->assertStringContainsString(
            'Shared Van Finance Support covers Wallet / Collection / Receipt / Remittance inspection',
            $contract,
        );
    }
}
