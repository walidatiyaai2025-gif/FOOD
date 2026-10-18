<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class AdminSingleGlobalHeaderContractTest extends TestCase
{
    public function test_every_canonical_admin_page_renders_one_shared_global_header(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views/admin'))
        );

        $checked = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $view = file_get_contents($file->getPathname());

            if (! is_string($view)) {
                continue;
            }

            if (
                str_starts_with($file->getFilename(), '_')
                || ! str_contains($view, "@include('admin._sidebar")
                || preg_match('/<main\\b/i', $view) !== 1
            ) {
                continue;
            }

            $this->assertStringNotContainsString('foodex-page-header', $view, $file->getPathname());
            $this->assertSame(
                0,
                preg_match('/<header\\b[^>]*foodex-page-toolbar/i', $view),
                $file->getPathname().' must keep page toolbars inside content rather than creating a second semantic page header.'
            );

            $checked++;
            $this->assertSame(
                1,
                substr_count($view, "@include('admin._global-header')"),
                $file->getPathname().' must render exactly one shared global header.'
            );
            $this->assertStringNotContainsString(
                "@include('admin._live-notifications'",
                $view,
                $file->getPathname().' must not render page-local notification/account chrome.'
            );
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_global_header_is_the_only_owner_of_platform_search_and_account_utilities(): void
    {
        $header = file_get_contents(resource_path('views/admin/_global-header.blade.php'));
        $notifications = file_get_contents(resource_path('views/admin/_live-notifications.blade.php'));

        $this->assertIsString($header);
        $this->assertIsString($notifications);
        $this->assertSame(1, substr_count($header, 'data-foodex-global-header'));
        $this->assertSame(1, substr_count($header, "@include('admin._global-search'"));
        $this->assertSame(1, substr_count($header, "@include('admin._live-notifications'"));
        $this->assertStringContainsString("@include('admin._account-menu'", $notifications);
    }

    public function test_desktop_global_header_keeps_search_geometrically_centered_without_duplicate_brand(): void
    {
        $header = file_get_contents(resource_path('views/admin/_global-header.blade.php'));
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($header);
        $this->assertIsString($components);
        $this->assertStringContainsString('class="foodex-global-header-spacer" aria-hidden="true"', $header);
        $this->assertStringNotContainsString('class="foodex-global-header-brand"', $header);
        $this->assertStringContainsString(
            'grid-template-columns:minmax(0,1fr) minmax(220px,560px) minmax(0,1fr)',
            $components
        );
        $this->assertStringContainsString('.foodex-global-header-spacer{display:block;width:100%;min-width:0}', $components);
        $this->assertStringContainsString('.foodex-global-header-spacer{display:none}', $components);
    }

    public function test_mobile_navigation_launcher_does_not_create_a_second_brand_header(): void
    {
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString('.foodex-mobile-web-bar>.foodex-mobile-nav-launcher{pointer-events:auto}', $sidebar);
        $this->assertStringContainsString('.foodex-mobile-web-brand{display:none!important}', $sidebar);
        $this->assertStringContainsString('bar.append(launcher);', $sidebar);
        $this->assertStringNotContainsString("mobileBrand.textContent = 'FOODEX'", $sidebar);
    }
}
