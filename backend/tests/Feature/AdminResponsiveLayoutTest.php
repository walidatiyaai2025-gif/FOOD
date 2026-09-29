<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminResponsiveLayoutTest extends TestCase
{
    public function test_shared_admin_shell_is_fluid_on_widescreen_and_has_tablet_mobile_breakpoints(): void
    {
        $shared = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($shared);
        $this->assertStringContainsString(
            '.foodex-admin-page{width:100%;max-width:none!important;min-width:0;',
            $shared,
        );
        $this->assertStringContainsString('@media(min-width:1440px)', $shared);
        $this->assertStringContainsString('@media(min-width:1800px)', $shared);
        $this->assertStringContainsString('@media(max-width:1023px)', $shared);
        $this->assertStringContainsString('@media(max-width:767px)', $shared);
        $this->assertStringContainsString('@media(max-width:479px)', $shared);
        $this->assertStringContainsString(
            '.table-wrap,.module-table-wrap,.foodex-table-wrap{width:100%;max-width:100%;overflow:auto;',
            $shared,
        );
    }

    public function test_retail_dashboard_uses_full_available_width_and_common_tablet_breakpoint(): void
    {
        $view = file_get_contents(resource_path('views/admin/b2c-workspace.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString(
            '.content{width:100%;max-width:none;margin:0;',
            $view,
        );
        $this->assertStringContainsString('@media(min-width:1600px)', $view);
        $this->assertStringContainsString('@media(min-width:1900px)', $view);
        $this->assertStringContainsString('@media(max-width:1023px)', $view);
        $this->assertStringNotContainsString('@media(max-width:860px)', $view);
    }

    public function test_b2b_and_b2c_metric_cards_support_up_to_eight_in_one_wide_row_with_icons(): void
    {
        $b2b = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $b2c = file_get_contents(resource_path('views/admin/b2c-workspace.blade.php'));

        $this->assertIsString($b2b);
        $this->assertIsString($b2c);

        $this->assertStringContainsString(
            'grid-template-columns:repeat(var(--foodex-card-columns,4),minmax(0,1fr))',
            $b2b,
        );
        $this->assertStringContainsString(
            'style="--foodex-card-columns:{{ min(8,max(1,count($counts))) }}"',
            $b2b,
        );
        $this->assertStringContainsString('metric-card-icon', $b2b);
        $this->assertStringContainsString("'warehouses'=>'inventory'", $b2b);
        $this->assertStringContainsString("'drivers'=>'delivery'", $b2b);
        $this->assertStringContainsString("'finance'=>'revenue'", $b2b);

        $this->assertStringContainsString(
            'grid-template-columns:repeat(var(--foodex-card-columns,4),minmax(0,1fr))',
            $b2c,
        );
        $this->assertStringContainsString(
            'style="--foodex-card-columns:{{ min(8,max(1,count($counts))) }}"',
            $b2c,
        );
        $this->assertStringContainsString('module-card-icon', $b2c);
        $this->assertStringContainsString("'incoming_orders'=>'delivery'", $b2c);
        $this->assertStringContainsString("'customers'=>'customers'", $b2c);
        $this->assertStringContainsString("'inventory'=>'inventory'", $b2c);

        $this->assertStringContainsString(
            '@media(max-width:1279px){.cards{grid-template-columns:repeat(4,minmax(0,1fr))}',
            $b2b,
        );
        $this->assertStringContainsString(
            '@media(max-width:1279px){.module-cards{grid-template-columns:repeat(4,minmax(0,1fr))}}',
            $b2c,
        );
        $this->assertStringContainsString(
            '@media(max-width:900px){.module-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}',
            $b2c,
        );
        $this->assertStringContainsString('.cards{grid-template-columns:1fr}', $b2b);
        $this->assertStringContainsString('.module-cards{grid-template-columns:1fr}', $b2c);
    }

    public function test_independent_admin_pages_use_shared_sidebar_width_and_tablet_breakpoint(): void
    {
        foreach ([
            'reports.blade.php',
            'mobile-settings.blade.php',
        ] as $file) {
            $view = file_get_contents(resource_path('views/admin/'.$file));

            $this->assertIsString($view);
            $this->assertStringContainsString('var(--foodex-sidebar-width)', $view, $file);
            $this->assertStringContainsString('@media(max-width:1023px)', $view, $file);
        }

        foreach ([
            'catalog-management.blade.php',
            'lookup-management.blade.php',
        ] as $file) {
            $view = file_get_contents(resource_path('views/admin/'.$file));

            $this->assertIsString($view);
            $this->assertStringContainsString('@media(max-width:1023px)', $view, $file);
            $this->assertStringNotContainsString('@media(max-width:1000px)', $view, $file);
        }
    }
}
