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
        $this->assertStringContainsString("{{ $invoice['number'] }}", $view);
        $this->assertStringNotContainsString("{{ strtoupper($order['channel']) }}", $view);
        $this->assertStringNotContainsString("<td>{{ $order['status'] }}</td>", $view);
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
}
